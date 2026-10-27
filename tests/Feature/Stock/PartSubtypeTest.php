<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Filament\Resources\PartSubtypes\Pages\CreatePartSubtype;
use App\Filament\Resources\PartSubtypes\Pages\ListPartSubtypes;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\StockBalanceReport;
use App\Services\MotorModelService;
use App\Services\PartService;
use App\Services\PartSubtypeService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(PartSubtypeService::class);
    $this->parts = app(PartService::class);
    $this->admin = User::factory()->admin()->create();
    $this->unit = Unit::factory()->create();
    $this->sheet = PartSubtype::query()->where('type', 'raw_material')->where('name', 'Silisli sac')->sole();
});

function partWith(array $data = []): Part
{
    return test()->parts->create(['code' => 'P-'.uniqid(), 'name' => 'Deneme', 'unit_id' => test()->unit->id, 'type' => PartType::RawMaterial, ...$data]);
}

it('kurulumda örnek alt türler açılır', function () {
    expect(PartSubtype::where('type', 'raw_material')->pluck('name')->all())->toContain('Silisli sac', 'Mil çeliği')
        ->and(PartSubtype::where('type', 'semi_finished')->pluck('name')->all())->toContain('Stator paketi', 'Mil+rotor')
        ->and(PartSubtype::where('type', 'finished_good')->orderBy('id')->pluck('name')->all())->toBe(['Trifaze', 'Monofaze MD', 'Monofaze MSD', 'Vibrasyon', 'Bor yağı pompası'])
        ->and(PartSubtype::where('type', 'indirect_consumable')->count())->toBe(0);
});

it('ad aynı ana türde tekildir (Türkçe I/ı ve büyük/küçük harf önemsiz); başka ana türde tekrar edebilir', function () {
    expect(fn () => $this->service->create(['type' => 'raw_material', 'name' => '  SİLİSLİ SAC ']))
        ->toThrow(BusinessRuleException::class, 'zaten var')
        ->and(fn () => DB::transaction(fn () => DB::table('part_subtypes')->insert(['type' => 'raw_material', 'name' => 'silisli sac'])))
        ->toThrow(QueryException::class, 'part_subtypes_type_name_unique');

    $other = $this->service->create(['type' => 'component', 'name' => 'Silisli sac']);
    expect($other->type)->toBe(PartType::Component);
});

it('ürünün alt türü ürünün ana türüne ait olmalı (servis ve veritabanı); pasif alt tür yeni seçilemez', function () {
    $bearing = PartSubtype::query()->where('type', 'component')->where('name', 'Rulman')->sole();

    expect(fn () => partWith(['subtype_id' => $bearing->id]))->toThrow(BusinessRuleException::class, 'Komponent türüne ait');

    $part = partWith(['subtype_id' => $this->sheet->id]);
    expect($part->fresh()->typeLabel())->toBe('Hammadde › Silisli sac');

    expect(fn () => DB::transaction(fn () => DB::table('parts')->where('id', $part->id)->update(['subtype_id' => $bearing->id])))
        ->toThrow(QueryException::class, 'ana türüne ait');

    $this->service->deactivate($this->sheet);
    expect(fn () => partWith(['subtype_id' => $this->sheet->id]))->toThrow(BusinessRuleException::class, 'pasif')
        ->and($part->fresh()->subtype_id)->toBe($this->sheet->id);
});

it('ürünün türü değişince alt türü temizlenir ve denetim izine yazılır', function () {
    $part = partWith(['subtype_id' => $this->sheet->id]);

    $part = $this->parts->update($part, ['type' => PartType::Component], $part->lock_version);

    expect($part->fresh()->subtype_id)->toBeNull()
        ->and(Activity::where('subject_type', 'part')->where('subject_id', $part->id)->where('event', 'updated')->latest('id')->first()->attribute_changes['old'])
        ->toHaveKey('subtype_id', $this->sheet->id);
});

it('ürünlerde kullanılan alt türün ana türü değiştirilemez (servis ve veritabanı)', function () {
    partWith(['subtype_id' => $this->sheet->id]);

    expect(fn () => $this->service->update($this->sheet, ['type' => 'component'], $this->sheet->lock_version))
        ->toThrow(BusinessRuleException::class, 'değiştirilemez')
        ->and(fn () => DB::transaction(fn () => DB::table('part_subtypes')->where('id', $this->sheet->id)->update(['type' => 'component'])))
        ->toThrow(QueryException::class, 'değiştirilemez');

    $unused = $this->service->create(['type' => 'raw_material', 'name' => 'Kullanılmayan']);
    expect($this->service->update($unused, ['type' => 'component'], $unused->lock_version)->type)->toBe(PartType::Component);
});

it('mamul alt türü motor modelinden girilir', function () {
    $models = app(MotorModelService::class);
    $trifaze = PartSubtype::query()->where('type', 'finished_good')->where('name', 'Trifaze')->sole();
    $md = PartSubtype::query()->where('type', 'finished_good')->where('name', 'Monofaze MD')->sole();

    $model = $models->create(['code' => 'M63-MD', 'name' => '63 Tip Monofaze', 'subtype_id' => $trifaze->id]);
    expect($model->finishedPart->subtype_id)->toBe($trifaze->id);

    $model = $models->update($model, ['subtype_id' => $md->id], $model->lock_version);
    expect($model->finishedPart()->first()->subtype_id)->toBe($md->id)
        ->and(fn () => $models->update($model->fresh(), ['subtype_id' => $this->sheet->id], $model->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'Hammadde türüne ait');

    // Mamul kartının alt türü ürün formundan değiştirilemez.
    $part = $model->finishedPart()->first();
    expect(fn () => $this->parts->update($part, ['subtype_id' => $trifaze->id], $part->lock_version))
        ->toThrow(BusinessRuleException::class, 'motor modelinden yönetilir');
});

it('ürün listesi ve stok raporu alt türe göre süzülür', function () {
    $this->actingAs($this->admin);
    $sheet = partWith(['subtype_id' => $this->sheet->id]);
    $plain = partWith();
    app(StockService::class)->stockIn($sheet, 5, $this->admin);
    app(StockService::class)->stockIn($plain, 5, $this->admin);

    Livewire::test(ListParts::class)
        ->filterTable('subtype_id', [$this->sheet->id])
        ->assertCanSeeTableRecords([$sheet])
        ->assertCanNotSeeTableRecords([$plain]);

    $rows = app(StockBalanceReport::class)->rows(ReportFilters::fromArray(['preset' => 'this_month', 'subtype_id' => $this->sheet->id]));
    expect(collect($rows)->pluck('code')->all())->toBe([$sheet->code])
        ->and($rows[0]['subtype'])->toBe('Silisli sac');
});

it('alt tür ekranını yönetici yönetir, diğer roller yalnızca görür', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreatePartSubtype::class)
        ->fillForm(['type' => 'semi_finished', 'name' => 'Rotor saçı'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PartSubtype::where('name', 'Rotor saçı')->sole()->type)->toBe(PartType::SemiFinished);

    $this->actingAs(User::factory()->warehouse()->create());
    Livewire::test(ListPartSubtypes::class)->assertOk()->assertSee('Silisli sac');
    $this->get(CreatePartSubtype::getUrl())->assertForbidden();
});
