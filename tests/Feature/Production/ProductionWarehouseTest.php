<?php

use App\Enums\MotorUnitStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Models\MotorUnit;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

/*
 * Aşama B2: iş emri malzemeyi üretim deposundan (yarı mamulü kendi deposundan) çeker, çıktıyı çıktı deposuna koyar;
 * motorun deposu, depodan sevk, motor seçimiyle mamul transferi.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->depo = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create();
    $this->stock = app(StockService::class);
    $this->workOrders = app(WorkOrderService::class);
    $this->production = app(ProductionService::class);
    $this->ham = Warehouse::query()->where('code', 'HAM')->sole();
    $this->urt = Warehouse::query()->where('code', 'URT')->sole();
    $this->ym = Warehouse::query()->where('code', 'YM')->sole();
    $this->mml = Warehouse::query()->where('code', 'MML')->sole();
});

function balanceIn($part, Warehouse $warehouse): string
{
    return (string) (StockBalance::query()->where('part_id', $part->id)->where('warehouse_id', $warehouse->id)->value('quantity') ?? '0');
}

/** Varsayılan depolarla açılan (üretim deposundan çeken) motor iş emri. */
function urtMotorWorkOrder(int $quantity = 2)
{
    $t = test();

    return $t->workOrders->create(['product_part_id' => $t->f->model->finished_part_id, 'quantity' => $quantity, 'planned_end' => today()->addWeek()], $t->admin);
}

it('yeni iş emri üretim deposundan çeker; yarı mamul kendi deposundan; çıktı ürünün deposuna', function () {
    $wo = urtMotorWorkOrder();
    $stator = $this->workOrders->create(['product_part_id' => $this->f->stator->id, 'quantity' => 1, 'planned_end' => today()->addWeek()], $this->admin);
    $warehouses = $wo->materials()->with('part')->get()->mapWithKeys(fn ($m) => [$m->part->code => $m->warehouse_id]);

    expect($wo->source_warehouse_id)->toBe($this->urt->id)
        ->and($wo->target_warehouse_id)->toBe($this->mml->id)
        ->and($stator->target_warehouse_id)->toBe($this->ym->id)
        ->and($warehouses->all())->toBe(['YM-STATOR-71' => $this->ym->id, 'RUL-6202' => $this->urt->id, 'SARF-VERNIK' => $this->urt->id]);
});

it('tamamlamada malzeme satırın deposundan düşer, motorlar çıktı deposuna girer', function () {
    $this->f->stockUp($this->depo);   // ana depolara (HAM, YM)
    $wo = $this->workOrders->start(urtMotorWorkOrder(), $this->admin);

    // Üretim deposunda malzeme yok: eksik deposuyla listelenir.
    expect(fn () => $this->production->complete($wo, $this->admin))
        ->toThrow(InsufficientStockException::class, 'RUL-6202 '.$this->f->bearing->name.' (Üretim deposu)');

    $this->stock->transfer($this->f->bearing, $this->ham, $this->urt, 10, $this->depo, 'Hat besleme');
    $this->stock->transfer($this->f->varnish, $this->ham, $this->urt, 1, $this->depo, 'Hat besleme');
    $this->production->complete($wo->fresh(), $this->admin);

    $movements = StockMovement::where('reference_type', 'work_order')->where('reference_id', $wo->id)->with('part')->get();
    $by = fn (string $code) => $movements->first(fn ($m) => $m->part->code === $code && $m->type === StockMovementType::ProductionConsumption);

    expect($by('RUL-6202')->warehouse_id)->toBe($this->urt->id)
        ->and($by('YM-STATOR-71')->warehouse_id)->toBe($this->ym->id)
        ->and($movements->firstWhere('type', StockMovementType::ProductionOutput)->warehouse_id)->toBe($this->mml->id)
        ->and(MotorUnit::where('work_order_id', $wo->id)->pluck('warehouse_id')->unique()->all())->toBe([$this->mml->id])
        ->and(balanceIn($this->f->bearing, $this->urt))->toBe('6.000')
        ->and(balanceIn($this->f->bearing, $this->ham))->toBe('990.000');

    $this->artisan('stock:reconcile')->assertSuccessful();
});

it('depolar yalnızca planlandı durumunda değişir; malzeme deposu değişince satırlar güncellenir', function () {
    $wo = urtMotorWorkOrder();

    $wo = $this->workOrders->update($wo, ['source_warehouse_id' => null, 'target_warehouse_id' => $this->ym->id], $wo->lock_version);
    $bearing = $wo->materials()->where('part_id', $this->f->bearing->id)->sole();

    expect($bearing->warehouse_id)->toBe($this->ham->id)->and($wo->target_warehouse_id)->toBe($this->ym->id);

    $wo = $this->workOrders->start($wo, $this->admin);
    expect(fn () => $this->workOrders->update($wo->fresh(), ['source_warehouse_id' => $this->urt->id], $wo->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'yalnızca planlandı');

    $extra = Warehouse::factory()->create();
    $extra->is_active = false;
    $extra->save();
    expect(fn () => $this->workOrders->create(['product_part_id' => $this->f->stator->id, 'quantity' => 1, 'planned_end' => today(), 'target_warehouse_id' => $extra->id], $this->admin))
        ->toThrow(BusinessRuleException::class, 'pasif');
});

it('malzeme durumu satırın deposunu kullanır; eksiği tamamla başka depodan transfer önerir', function () {
    $this->stock->stockIn($this->f->bearing, 100, $this->depo);   // HAM
    $wo = urtMotorWorkOrder();
    $bearing = $wo->materials()->where('part_id', $this->f->bearing->id)->sole();

    $status = collect($this->workOrders->materialStatus($wo))->first(fn ($row) => $row['material']->is($bearing));
    expect($status['available'])->toBe('0.000')->and($status['missing'])->toBe('4.000');

    $this->actingAs($this->depo);
    Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $wo, 'pageClass' => ViewWorkOrder::class])
        ->assertSee('HAM: 100')
        ->mountAction(TestAction::make('resolveShortage')->table($bearing))
        ->assertSchemaStateSet(['mode' => 'transfer', 'source_warehouse_id' => $this->ham->id, 'quantity' => '4'], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Transfer kaydedildi: RUL-6202');

    expect(balanceIn($this->f->bearing, $this->urt))->toBe('4.000')
        ->and(balanceIn($this->f->bearing, $this->ham))->toBe('96.000');
});

describe('motorlar', function () {
    beforeEach(function () {
        $this->f->stockUp($this->depo);
        $wo = $this->f->startedMotorWorkOrder($this->admin, 3);
        $this->production->complete($wo, $this->admin);
        $this->wo = $wo->fresh();
        $this->units = MotorUnit::where('work_order_id', $wo->id)->orderBy('id')->get();
        $this->finished = $this->f->model->finishedPart;
    });

    it('motor seçimiyle başka depoya aktarılır: motor başına transfer, referans motor', function () {
        $this->production->transferMotors($this->units->take(2), $this->urt, $this->depo, 'Test alanına');

        $legs = StockMovement::where('part_id', $this->finished->id)->where('type', 'transfer')->get();

        expect($legs)->toHaveCount(4)
            ->and($legs->pluck('reference_type')->unique()->all())->toBe(['motor_unit'])
            ->and(balanceIn($this->finished, $this->mml))->toBe('1.000')
            ->and(balanceIn($this->finished, $this->urt))->toBe('2.000')
            ->and($this->units[0]->fresh()->warehouse_id)->toBe($this->urt->id);

        $this->artisan('stock:reconcile')->assertSuccessful();
    });

    it('stokta olmayan veya zaten hedefte olan motor varsa hiçbiri aktarılmaz; yetkisiz aktaramaz', function () {
        $this->production->ship([$this->units[0]], $this->depo);

        expect(fn () => $this->production->transferMotors($this->units, $this->urt, $this->depo))
            ->toThrow(BusinessRuleException::class, $this->units[0]->serial_number)
            ->and(fn () => $this->production->transferMotors([$this->units[1]], $this->mml, $this->depo))
            ->toThrow(BusinessRuleException::class, 'Mamul / sevkiyat deposu dışındaki')
            ->and(fn () => $this->production->transferMotors([$this->units[1]], $this->urt, User::factory()->operator()->create()))
            ->toThrow(BusinessRuleException::class, 'yetkiniz yok')
            ->and($this->units[1]->fresh()->warehouse_id)->toBe($this->mml->id);
    });

    it('mamulde motorsuz transfer servis ve veritabanında reddedilir', function () {
        expect(fn () => $this->stock->transfer($this->finished, $this->mml, $this->urt, 1, $this->depo))
            ->toThrow(BusinessRuleException::class, 'mamul kartı');

        expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([
            'part_id' => $this->finished->id, 'warehouse_id' => $this->mml->id, 'type' => 'transfer', 'quantity' => '-1',
            'balance_before' => '3', 'balance_after' => '2', 'transfer_id' => (string) Str::uuid(), 'user_id' => $this->depo->id,
        ])))->toThrow(QueryException::class, 'motor transferiyle');
    });

    it('motor bulunduğu depodan sevk edilir', function () {
        $this->production->transferMotors([$this->units[0]], $this->urt, $this->depo);
        $this->production->ship([$this->units[0]], $this->depo);

        $shipment = StockMovement::where('type', StockMovementType::Shipment->value)->sole();

        expect($shipment->warehouse_id)->toBe($this->urt->id)
            ->and(balanceIn($this->finished, $this->urt))->toBe('0.000')
            ->and(balanceIn($this->finished, $this->mml))->toBe('2.000')
            ->and($this->units[0]->fresh()->status)->toBe(MotorUnitStatus::Shipped);
    });

    it('motorlar çıktı deposundan taşındıysa geri alma reddedilir; geri aktarınca yapılır', function () {
        $this->production->transferMotors([$this->units[2]], $this->urt, $this->depo);

        expect(fn () => $this->production->reverse($this->wo, $this->admin, 'Hatalı'))
            ->toThrow(BusinessRuleException::class, 'geri aktarın');

        $this->production->transferMotors([$this->units[2]], $this->mml, $this->depo);
        $this->production->reverse($this->wo->fresh(), $this->admin, 'Hatalı');

        expect(balanceIn($this->finished, $this->mml))->toBe('0.000')
            ->and(MotorUnit::where('work_order_id', $this->wo->id)->where('status', MotorUnitStatus::Voided->value)->count())->toBe(3);
    });

    it('stock:reconcile depo bazında motor sayısı farkını yakalar', function () {
        User::factory()->admin()->create();
        // Servis dışından motorun deposu değişir (bakiye hareketsiz kalır).
        DB::table('motor_units')->where('id', $this->units[0]->id)->update(['warehouse_id' => $this->urt->id]);

        $this->artisan('stock:reconcile')->assertFailed();
    });
});
