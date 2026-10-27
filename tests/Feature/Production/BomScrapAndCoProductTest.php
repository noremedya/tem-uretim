<?php

use App\Enums\PartType;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\BomEditor;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Filament\Support\BomLineForm;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOutput;
use App\Services\BomService;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Aşama D: fire miktarla (brüt/fire) ve fire kartı (hurda), eş ürünler (aynı kalıpta stator + rotor paketi),
 * reçete kopyalama, ağaç ve ihtiyaç, Reçeteler ekranı.
 *
 * Kurulum: Stator paketi 71-70 (ana) ← silisli sac brüt 6,61 kg / fire 2,88 kg (fire → HURDA-SAC);
 * eş ürün: Rotor paketi 71-70 (birim başına 1).
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->boms = app(BomService::class);
    $this->workOrders = app(WorkOrderService::class);
    $this->production = app(ProductionService::class);
    $this->stock = app(StockService::class);
    $this->kg = Unit::factory()->create(['name' => 'kg-d', 'allows_decimal' => true]);
    $this->piece = Unit::factory()->whole()->create(['name' => 'adet-d']);

    $this->sheet = Part::factory()->create(['code' => 'HM-SAC', 'type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    $this->scrapSheet = Part::factory()->create(['code' => 'HURDA-SAC', 'type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    $this->stator = Part::factory()->create(['code' => 'YM-STP-71-70', 'type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
    $this->rotor = Part::factory()->create(['code' => 'YM-RTP-71-70', 'type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);

    $this->line = BomLineForm::save($this->stator, [
        'component_part_id' => $this->sheet->id, 'scrap_mode' => 'quantity',
        'gross_per_unit' => '6,61', 'scrap_quantity_per_unit' => '2,88', 'scrap_part_id' => $this->scrapSheet->id,
    ]);
});

function balanceOfPart(Part $part): string
{
    return (string) StockBalance::query()->where('part_id', $part->id)->sum('quantity');
}

function statorOrder(int $quantity = 10): WorkOrder
{
    $t = test();

    return $t->workOrders->create(['product_part_id' => $t->stator->id, 'quantity' => $quantity, 'planned_end' => today(), 'source_warehouse_id' => null], $t->admin);
}

it('brüt ve fire miktarıyla girilen satırda net = brüt − fire; oran sıfırdır', function () {
    expect($this->line)
        ->quantity_per_unit->toBe('3.730')
        ->scrap_rate->toBe('0.00')
        ->scrap_quantity_per_unit->toBe('2.880')
        ->and($this->line->grossPerUnit())->toBe('6.610');

    expect(fn () => $this->boms->update($this->line, '3,73', '5', $this->line->lock_version, '2,88'))
        ->toThrow(BusinessRuleException::class, 'ya oranla ya miktarla')
        ->and(fn () => BomLineForm::save($this->rotor, ['component_part_id' => $this->sheet->id, 'scrap_mode' => 'quantity', 'gross_per_unit' => '2', 'scrap_quantity_per_unit' => '2']))
        ->toThrow(BusinessRuleException::class, 'brüt miktardan küçük')
        ->and(fn () => DB::transaction(fn () => DB::table('bom_items')->where('id', $this->line->id)->update(['scrap_rate' => 5])))
        ->toThrow(QueryException::class, 'bom_items_scrap_quantity_check');
});

it('fire kartı malzemeyle aynı birimde olmalı ve mamul olamaz (servis ve veritabanı)', function () {
    $pieceScrap = Part::factory()->create(['type' => PartType::RawMaterial, 'unit_id' => $this->piece->id]);
    $other = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);

    expect(fn () => $this->boms->add($other, $this->sheet, 1, 0, null, $pieceScrap))
        ->toThrow(BusinessRuleException::class, 'aynı birimde')
        ->and(fn () => DB::transaction(fn () => DB::table('bom_items')->where('id', $this->line->id)->update(['scrap_part_id' => $pieceScrap->id])))
        ->toThrow(QueryException::class, 'aynı birimde');
});

it('iş emrinde standart fire adet × birim başına fire; gerçekleşen fire fire kartına girer, geri almada ters kaydedilir', function () {
    $wo = statorOrder(10);
    $material = $wo->materials()->sole();

    expect($material)->required_quantity->toBe('37.300')->planned_scrap->toBe('28.800')->scrap_part_id->toBe($this->scrapSheet->id);

    $this->stock->stockIn($this->sheet, 100, $this->admin);
    $wo = $this->workOrders->start($wo, $this->admin);
    $this->production->complete($wo, $this->admin, [$this->sheet->id => '30']);

    $scrapIn = StockMovement::query()->where('part_id', $this->scrapSheet->id)->sole();

    expect($scrapIn)->type->toBe(StockMovementType::ProductionOutput)->quantity->toBe('30.000')
        ->and(balanceOfPart($this->sheet))->toBe('32.700')
        ->and(balanceOfPart($this->scrapSheet))->toBe('30.000');

    $this->production->reverse($wo->fresh(), $this->admin, 'Hatalı giriş');

    expect(balanceOfPart($this->scrapSheet))->toBe('0.000')->and(balanceOfPart($this->sheet))->toBe('100.000');
});

describe('eş ürün', function () {
    beforeEach(function () {
        $this->coProduct = $this->boms->addCoProduct($this->stator, $this->rotor, 1);
    });

    it('iş emrine çıktı olarak kopyalanır, adetle güncellenir; tamamlamada ana ürünle birlikte stoğa girer', function () {
        $wo = statorOrder(10);
        $output = $wo->outputs()->sole();

        expect($output)->part_id->toBe($this->rotor->id)->quantity->toBe('10.000');

        $wo = $this->workOrders->update($wo, ['quantity' => '12'], $wo->lock_version);
        expect($wo->outputs()->sole()->quantity)->toBe('12.000');

        $this->stock->stockIn($this->sheet, 200, $this->admin);
        $this->production->complete($this->workOrders->start($wo, $this->admin), $this->admin);

        $rotorIn = StockMovement::query()->where('part_id', $this->rotor->id)->sole();
        expect($rotorIn->type)->toBe(StockMovementType::ProductionOutput)
            ->and($rotorIn->warehouse_id)->toBe($wo->target_warehouse_id)
            ->and(balanceOfPart($this->stator))->toBe('12.000')
            ->and(balanceOfPart($this->rotor))->toBe('12.000');

        // Eş ürün bu arada kullanıldıysa geri alma reddedilir.
        $this->stock->stockOut($this->rotor, 1, $this->admin, 'Kullanıldı');
        expect(fn () => $this->production->reverse($wo->fresh(), $this->admin, 'Hatalı'))->toThrow(BusinessRuleException::class, 'kullanılmış');
    });

    it('kurallar: yarı mamul, tek ana ürün, eş ürünün reçetesi ve eş ürünü yok, döngü yok (servis ve veritabanı)', function () {
        $other = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
        $withBom = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
        $this->boms->add($withBom, $this->sheet, 1);

        expect(fn () => $this->boms->addCoProduct($other, $this->rotor, 1))->toThrow(BusinessRuleException::class, 'zaten YM-STP-71-70')
            ->and(fn () => $this->boms->addCoProduct($this->stator, $withBom, 1))->toThrow(BusinessRuleException::class, 'kendi reçetesi')
            ->and(fn () => $this->boms->addCoProduct($this->rotor, $other, 1))->toThrow(BusinessRuleException::class, 'eş ürünün eş ürünü')
            ->and(fn () => $this->boms->addCoProduct($this->stator, $this->sheet, 1))->toThrow(BusinessRuleException::class, 'yarı mamul')
            ->and(fn () => $this->boms->add($this->rotor, $this->sheet, 1))->toThrow(BusinessRuleException::class, 'kendi reçetesi olamaz')
            ->and(fn () => DB::transaction(fn () => DB::table('bom_items')->insert(['parent_part_id' => $this->rotor->id, 'component_part_id' => $this->sheet->id, 'quantity_per_unit' => 1, 'scrap_rate' => 0])))
            ->toThrow(QueryException::class, 'Eş ürünün kendi reçetesi olamaz')
            ->and(fn () => DB::transaction(fn () => DB::table('bom_co_products')->insert(['parent_part_id' => $this->stator->id, 'co_part_id' => $withBom->id, 'quantity_per_unit' => 1])))
            ->toThrow(QueryException::class, 'Reçetesi olan ürün eş ürün olamaz');

        // Ana ürünün reçetesinde (dolaylı) kullanılan ürün eş ürün olamaz.
        $inner = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
        $main = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
        $this->boms->add($main, $inner, 1);
        expect(fn () => $this->boms->addCoProduct($main, $inner, 1))->toThrow(BusinessRuleException::class, 'döngü');
    });

    it('eksik eş ürün için ana ürünün iş emri açılır (adet birim başına çıktıya göre)', function () {
        $this->coProduct = $this->boms->updateCoProduct($this->coProduct, 2, $this->coProduct->lock_version);
        $model = MotorModel::factory()->create();
        $this->boms->add($model->finishedPart, $this->rotor, 1);
        $motorWo = $this->workOrders->create(['product_part_id' => $model->finished_part_id, 'quantity' => 5, 'planned_end' => today()], $this->admin);
        $rotorLine = $motorWo->materials()->sole();
        $this->actingAs($this->admin);

        Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $motorWo, 'pageClass' => ViewWorkOrder::class])
            ->mountAction(TestAction::make('resolveShortage')->table($rotorLine))
            ->assertSchemaStateSet(['mode' => 'work_order'], 'mountedActionSchema0')
            ->setActionData(['mode' => 'work_order', 'quantity' => '5'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $opened = WorkOrder::query()->where('product_part_id', $this->stator->id)->sole();
        expect($opened->quantity)->toBe('3.000')
            ->and(WorkOrderOutput::query()->where('work_order_id', $opened->id)->sole()->quantity)->toBe('6.000');
    });
});

it('reçete kopyalanır; hedefte olan bileşen atlanır', function () {
    $copper = Part::factory()->create(['type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    $this->boms->add($this->stator, $copper, '0,8');
    $target = Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
    $this->boms->add($target, $copper, '1');

    expect($this->boms->copy($this->stator, $target))->toBe(1);

    $copied = $target->bomItems()->where('component_part_id', $this->sheet->id)->sole();
    expect($copied)->scrap_quantity_per_unit->toBe('2.880')->scrap_part_id->toBe($this->scrapSheet->id)
        ->and($target->bomItems()->where('component_part_id', $copper->id)->sole()->quantity_per_unit)->toBe('1.000')
        ->and(fn () => $this->boms->copy(Part::factory()->create(['type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]), $target))
        ->toThrow(BusinessRuleException::class, 'reçetesi yok');
});

it('ağaç ve toplam ihtiyaç her seviyede fireyle hesaplanır', function () {
    $winding = Part::factory()->create(['code' => 'YM-BOB', 'type' => PartType::SemiFinished, 'unit_id' => $this->piece->id]);
    $copper = Part::factory()->create(['code' => 'HM-BAKIR', 'type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    $this->boms->add($winding, $this->stator, 1, 10);   // %10 fire: 10 adet için 1 adet fazla
    $this->boms->add($winding, $copper, '0,5');

    $tree = $this->boms->tree($winding, 10);
    $statorNode = collect($tree)->first(fn ($n) => $n['part']->is($this->stator));

    expect($statorNode['total'])->toBe('11.000')
        ->and($statorNode['children'][0]['net'])->toBe('41.030')       // 11 × 3,73
        ->and($statorNode['children'][0]['scrap'])->toBe('31.680');    // 11 × 2,88

    $requirements = collect($this->boms->requirements($winding, 10))->mapWithKeys(fn ($r) => [$r['part']->code => $r['total']]);
    expect($requirements->all())->toBe(['HM-BAKIR' => '5.000', 'HM-SAC' => '72.710']);
});

it('Reçeteler ekranı: yönetici düzenler; muhasebe görür ama düzenleyemez; operatör giremez', function () {
    $this->actingAs($this->admin);
    $copper = Part::factory()->create(['type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);

    Livewire::withQueryParams(['part' => $this->stator->id])->test(BomEditor::class)
        ->assertOk()
        ->assertSee('Eş ürün yok')
        ->assertCanSeeTableRecords([$this->line])
        ->callAction(TestAction::make('addLine')->table(), data: ['component_part_id' => $copper->id, 'scrap_mode' => 'rate', 'quantity_per_unit' => '0,8', 'scrap_rate' => '2'])
        ->assertNotified('Bileşen eklendi')
        ->callAction('addCoProduct', data: ['co_part_id' => $this->rotor->id, 'quantity_per_unit' => '1'])
        ->assertNotified('Eş ürün eklendi')
        ->set('data.quantity', '10')
        ->assertSee('Toplam ihtiyaç');

    expect($this->stator->bomItems()->count())->toBe(2)->and($this->stator->coProducts()->sole()->co_part_id)->toBe($this->rotor->id);

    $this->actingAs(User::factory()->withRoles(Role::Accounting)->create());
    Livewire::withQueryParams(['part' => $this->stator->id])->test(BomEditor::class)
        ->assertOk()
        ->assertActionHidden(TestAction::make('addLine')->table())
        ->assertActionHidden('addCoProduct');

    $this->actingAs(User::factory()->operator()->create());
    $this->get(BomEditor::getUrl())->assertForbidden();
});

it('Reçeteler ekranı ürün görsellerini gösterir: satır tablosu, ağaç, özet ve bileşen arama listesi', function () {
    $this->actingAs($this->admin);
    $this->sheet->forceFill(['image_path' => 'part-images/sac.jpg'])->save();
    $this->stator->forceFill(['image_path' => 'part-images/stator.jpg'])->save();
    $bearing = Part::factory()->create(['code' => 'KOM-RUL-T', 'name' => 'Rulman <6202>', 'type' => PartType::Component, 'unit_id' => $this->piece->id, 'image_path' => 'part-images/rulman.jpg']);

    Livewire::withQueryParams(['part' => $this->stator->id])->test(BomEditor::class)
        ->assertOk()
        ->assertSeeHtml($this->sheet->fresh()->imageUrl())
        ->assertSeeHtml($this->stator->fresh()->imageUrl());

    $options = Closure::bind(fn () => BomLineForm::searchComponents('KOM-RUL-T', test()->stator), null, BomLineForm::class)();
    expect($options[$bearing->id])->toContain('<img src="'.e($bearing->imageUrl()).'"')
        ->and($options[$bearing->id])->toContain('Rulman &lt;6202&gt;');
});
