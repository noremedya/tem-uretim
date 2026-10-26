<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\BomEditor;
use App\Filament\Resources\BomTemplates\Pages\CreateBomTemplate;
use App\Filament\Resources\BomTemplates\Pages\EditBomTemplate;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Models\User;
use App\Services\BomService;
use App\Services\BomTemplateService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(BomTemplateService::class);
    $this->piece = Unit::factory()->whole()->create(['name' => 'adet-s']);
    $this->kg = Unit::factory()->create(['name' => 'kg-s', 'allows_decimal' => true]);
});

function subtype(string $type, string $name): PartSubtype
{
    return PartSubtype::query()->where('type', $type)->where('name', $name)->sole();
}

function partOf(string $type, string $subtype, ?Unit $unit = null, array $data = []): Part
{
    return Part::factory()->create(['type' => PartType::from($type), 'subtype_id' => subtype($type, $subtype)->id, 'unit_id' => ($unit ?? test()->piece)->id, ...$data]);
}

function template(string $name): BomTemplate
{
    return BomTemplate::query()->where('name', $name)->sole();
}

function lineOf(BomTemplate $template, string $label): BomTemplateLine
{
    return $template->lines()->where('label', $label)->sole();
}

it('kurulumda firmanın yapısına göre hazır şablonlar açılır', function () {
    expect(BomTemplate::query()->orderBy('id')->pluck('name')->all())->toBe([
        'Trifaze asenkron motor', 'Monofaze MD motor', 'Monofaze MSD motor', 'Vibrasyon motoru',
        'Stator paketi (pres)', 'Bobinajlı stator', 'Enjekte rotor', 'İşlenmiş mil', 'Mil+rotor',
    ]);

    // Motor şablonları Tem Motor B3 montaj parça listesine göre (çizimdeki parça numaralarıyla).
    $trifaze = template('Trifaze asenkron motor');
    expect($trifaze->drawing)->toBe('b3-trifaze')
        ->and($trifaze->lines->whereNotNull('drawing_number')->pluck('drawing_number')->unique()->sort()->values()->all())->toBe(range(1, 15))
        ->and(lineOf($trifaze, 'Gövde')->quantity_per_unit)->toBeNull()
        ->and(lineOf($trifaze, 'Ön rulman')->drawing_number)->toBe(15)
        ->and(lineOf($trifaze, 'Arka rulman')->subtype->name)->toBe('Rulman')
        ->and(template('Monofaze MD motor')->lines->pluck('label')->all())->toContain('Daimi kondansatör')->not->toContain('Start kondansatörü')
        ->and(template('Monofaze MSD motor')->lines->pluck('label')->all())->toContain('Start kondansatörü', 'Daimi kondansatör', 'Merkezkaç anahtarı')
        ->and(Unit::query()->where('name', 'Gram')->value('allows_decimal'))->toBeTrue()
        ->and(template('Vibrasyon motoru')->lines->pluck('label')->all())->not->toContain('Pervane')->toContain('Eksantrik saç')
        ->and(lineOf(template('Vibrasyon motoru'), 'Eksantrik saç')->quantity_per_unit)->toBeNull();

    $press = template('Stator paketi (pres)');
    expect(lineOf($press, 'Silisli sac'))->gross_per_unit->toBe('6.610')->scrap_quantity_per_unit->toBe('2.880')
        ->and(lineOf($press, 'Rotor paketi (eş ürün)')->isCoProduct())->toBeTrue();
});

it('motor şablonu uygulanır: seçilen satırlar eklenir, boş bırakılan atlanır, reçetede olan atlanır', function () {
    $model = MotorModel::factory()->create();
    $finished = $model->finishedPart;
    $template = template('Trifaze asenkron motor');
    $stator = partOf('semi_finished', 'Bobinajlı stator');
    $bearing = partOf('component', 'Rulman');
    $bolt = partOf('direct_consumable', 'Bağlantı elemanı');
    app(BomService::class)->add($finished, $bolt, 6);

    $result = $this->service->apply($template, $finished, [
        lineOf($template, 'Sargılı vernikli stator')->id => ['part_id' => $stator->id, 'quantity_per_unit' => '1'],
        lineOf($template, 'Ön rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '2', 'scrap_rate' => '0'],
        lineOf($template, 'Civata')->id => ['part_id' => $bolt->id, 'quantity_per_unit' => '8', 'scrap_rate' => '2'],
    ]);

    expect($result)->toBe(['added' => 2, 'skipped' => count($template->lines) - 2])
        ->and($finished->bomItems()->where('component_part_id', $bearing->id)->sole()->quantity_per_unit)->toBe('2.000')
        ->and($finished->bomItems()->where('component_part_id', $bolt->id)->sole()->quantity_per_unit)->toBe('6.000');
});

it('uygulama tek işlemdir: tür/alt tür uyumsuzluğu veya eksik miktar varsa hiçbir satır eklenmez', function () {
    $finished = MotorModel::factory()->create()->finishedPart;
    $template = template('Vibrasyon motoru');
    $stator = partOf('semi_finished', 'Bobinajlı stator');
    $bearing = partOf('component', 'Rulman');
    $eccentric = partOf('component', 'Eksantrik saç');

    expect(fn () => $this->service->apply($template, $finished, [
        lineOf($template, 'Bobinajlı stator')->id => ['part_id' => $stator->id],
        lineOf($template, 'Eksantrik saç')->id => ['part_id' => $eccentric->id, 'quantity_per_unit' => null],
    ]))->toThrow(BusinessRuleException::class, 'Eksantrik saç: Miktar girilmelidir')
        ->and(fn () => $this->service->apply($template, $finished, [
            lineOf($template, 'Bobinajlı stator')->id => ['part_id' => $stator->id],
            lineOf($template, 'Eksantrik saç')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '4'],
        ]))->toThrow(BusinessRuleException::class, 'alt türünde değil')
        ->and(fn () => $this->service->apply($template, $finished, [lineOf($template, 'Bobinajlı stator')->id => ['part_id' => $bearing->id]]))
        ->toThrow(BusinessRuleException::class, 'Yarı mamul seçilmeli')
        ->and(fn () => $this->service->apply($template, $finished, []))->toThrow(BusinessRuleException::class, 'Hiçbir satıra ürün seçilmedi')
        ->and($finished->bomItems()->count())->toBe(0);
});

it('pres şablonu: sac brüt/fire miktarla ve fire kartıyla, rotor paketi eş ürün olarak eklenir', function () {
    $statorPack = partOf('semi_finished', 'Stator paketi');
    $rotorPack = partOf('semi_finished', 'Rotor paketi');
    $sheet = partOf('raw_material', 'Silisli sac', $this->kg);
    $scrap = Part::factory()->create(['type' => PartType::RawMaterial, 'unit_id' => $this->kg->id]);
    $template = template('Stator paketi (pres)');

    $this->service->apply($template, $statorPack, [
        lineOf($template, 'Silisli sac')->id => ['part_id' => $sheet->id, 'gross_per_unit' => '6,61', 'scrap_quantity_per_unit' => '2,88', 'scrap_part_id' => $scrap->id],
        lineOf($template, 'Rotor paketi (eş ürün)')->id => ['part_id' => $rotorPack->id, 'quantity_per_unit' => '1'],
    ]);

    $line = $statorPack->bomItems()->sole();
    expect($line)->quantity_per_unit->toBe('3.730')->scrap_quantity_per_unit->toBe('2.880')->scrap_part_id->toBe($scrap->id)
        ->and($statorPack->coProducts()->sole()->co_part_id)->toBe($rotorPack->id);
});

it('şablon ürün türüne uymalı; pasif şablon uygulanamaz', function () {
    $semi = partOf('semi_finished', 'Bobinajlı stator');

    expect(fn () => $this->service->apply(template('Trifaze asenkron motor'), $semi, []))->toThrow(BusinessRuleException::class, 'Mamul içindir');

    $template = $this->service->deactivate(template('Mil+rotor'));
    expect(fn () => $this->service->apply($template, partOf('semi_finished', 'Mil+rotor'), []))->toThrow(BusinessRuleException::class, 'Pasif şablon');
});

it('şablon tanımlama kuralları (servis ve veritabanı)', function () {
    $line = ['kind' => 'component', 'label' => 'Satır', 'component_type' => 'component'];

    expect(fn () => $this->service->create(['name' => 'X', 'product_type' => 'finished_good', 'lines' => []]))->toThrow(BusinessRuleException::class, 'en az bir satır')
        ->and(fn () => $this->service->create(['name' => 'X', 'product_type' => 'raw_material', 'lines' => [$line]]))->toThrow(BusinessRuleException::class, 'yarı mamul veya mamul')
        ->and(fn () => $this->service->create(['name' => 'X', 'product_type' => 'finished_good', 'lines' => [[...$line, 'component_type' => 'indirect_consumable']]]))->toThrow(BusinessRuleException::class, 'bileşen olarak girmez')
        ->and(fn () => $this->service->create(['name' => 'X', 'product_type' => 'semi_finished', 'lines' => [[...$line, 'kind' => 'co_product']]]))->toThrow(BusinessRuleException::class, 'eş ürün yarı mamul')
        ->and(fn () => $this->service->create(['name' => 'X', 'product_type' => 'finished_good', 'lines' => [[...$line, 'component_subtype_id' => subtype('raw_material', 'Silisli sac')->id]]]))->toThrow(BusinessRuleException::class, 'alt tür satırın türüne')
        ->and(fn () => $this->service->create(['name' => 'Trifaze asenkron motor', 'product_type' => 'finished_good', 'lines' => [$line]]))->toThrow(BusinessRuleException::class, 'zaten var');

    expect(fn () => DB::transaction(fn () => DB::table('bom_template_lines')->insert(['bom_template_id' => template('Mil+rotor')->id, 'label' => 'X', 'component_type' => 'component', 'component_subtype_id' => subtype('raw_material', 'Silisli sac')->id])))
        ->toThrow(QueryException::class, 'alt türü, satırın türüne ait olmalıdır');
});

it('şablon ekranında oluşturulur ve düzenlenir; reçete ekranından uygulanır', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateBomTemplate::class)
        ->fillForm(['name' => 'Fırçalı motor', 'product_type' => 'finished_good', 'lines' => [
            ['kind' => 'component', 'label' => 'Rulman', 'component_type' => 'component', 'component_subtype_id' => subtype('component', 'Rulman')->id, 'quantity_per_unit' => '2', 'scrap_rate' => '0'],
        ]])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = template('Fırçalı motor');
    expect(lineOf($template, 'Rulman')->quantity_per_unit)->toBe('2.000');

    Livewire::test(EditBomTemplate::class, ['record' => $template->getRouteKey()])
        ->assertSchemaStateSet(['product_type' => 'finished_good'])
        ->fillForm(['description' => 'Deneme'])
        ->call('save')
        ->assertHasNoFormErrors();

    $finished = MotorModel::factory()->create()->finishedPart;
    $bearing = partOf('component', 'Rulman');
    Livewire::withQueryParams(['part' => $finished->id])->test(BomEditor::class)
        ->callAction(TestAction::make('fromTemplate')->table(), data: [
            'template_id' => $template->id,
            'rows' => [lineOf($template->fresh(), 'Rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '2', 'scrap_rate' => '0']],
        ])
        ->assertNotified('1 satır eklendi');

    expect($finished->bomItems()->sole()->component_part_id)->toBe($bearing->id);
});
