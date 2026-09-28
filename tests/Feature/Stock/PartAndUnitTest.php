<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartService;
use App\Services\StockService;
use App\Services\UnitService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->warehouse()->create();
});

it('parça oluşturulurken 0 bakiyeli satır açılır', function () {
    $unit = Unit::factory()->create();

    $part = app(PartService::class)->create([
        'code' => ' P-100 ', 'name' => 'Bakır tel', 'unit_id' => $unit->id,
        'type' => PartType::RawMaterial, 'barcode' => '', 'critical_level' => '12,5',
    ]);

    expect($part->fresh())
        ->code->toBe('P-100')
        ->barcode->toBeNull()
        ->critical_level->toBe('12.500')
        ->is_active->toBeTrue()
        ->and((string) $part->stockBalance->quantity)->toBe('0.000');
});

it('formdan parça oluşturulur ve denetim izine yazılır', function () {
    $this->actingAs($this->user);
    $unit = Unit::factory()->create(['name' => 'kg']);

    Livewire::test(CreatePart::class)
        ->fillForm([
            'code' => 'HM-001', 'name' => 'Silisli sac', 'type' => PartType::RawMaterial->value,
            'unit_id' => $unit->id, 'barcode' => '8690000000001', 'critical_level' => '100,5',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $part = Part::where('code', 'HM-001')->sole();
    expect($part->critical_level)->toBe('100.500')
        ->and($part->stockBalance)->not->toBeNull()
        ->and(Activity::where('subject_type', 'part')->where('subject_id', $part->id)->exists())->toBeTrue();
});

it('geçersiz veya negatif kritik seviye reddedilir', function (string $value) {
    $this->actingAs($this->user);

    Livewire::test(CreatePart::class)
        ->fillForm([
            'code' => 'X-1', 'name' => 'X', 'type' => PartType::Consumable->value,
            'unit_id' => Unit::factory()->create()->id, 'critical_level' => $value,
        ])
        ->call('create')
        ->assertHasFormErrors(['critical_level']);
})->with(['-1', 'abc']);

it('hareketi olan parçanın birimi değiştirilemez', function () {
    $part = Part::factory()->withStock(1)->create();
    $other = Unit::factory()->create();

    expect(fn () => app(PartService::class)->update($part, ['unit_id' => $other->id], $part->lock_version))
        ->toThrow(BusinessRuleException::class, 'birimi değiştirilemez');

    expect($part->fresh()->unit_id)->not->toBe($other->id);

    // Formda alan kilitlidir.
    $this->actingAs($this->user);
    Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
        ->assertFormFieldDisabled('unit_id');
});

it('hareketi olmayan parçanın birimi değiştirilebilir', function () {
    $part = Part::factory()->create();
    $other = Unit::factory()->create();

    app(PartService::class)->update($part, ['unit_id' => $other->id], $part->lock_version);

    expect($part->fresh()->unit_id)->toBe($other->id);
});

it('parça düzenlemesi iki ayrı oturumda çakışırsa ikincisi reddedilir', function () {
    $part = Part::factory()->create(['name' => 'Eski']);
    $a = User::factory()->warehouse()->create();
    $b = User::factory()->admin()->create();

    $this->actingAs($a);
    $sessionA = Livewire::test(EditPart::class, ['record' => $part->getRouteKey()]);
    $this->actingAs($b);
    $sessionB = Livewire::test(EditPart::class, ['record' => $part->getRouteKey()]);

    $this->actingAs($a);
    $sessionA->fillForm(['name' => 'A adı'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($b);
    $sessionB->fillForm(['name' => 'B adı'])->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($part->fresh())->name->toBe('A adı')->lock_version->toBe(1);
});

it('parça pasifleştirilir ve aktifleştirilir; silinemez', function () {
    $this->actingAs($this->user);
    $part = Part::factory()->create();

    Livewire::test(ListParts::class)
        ->callAction(TestAction::make('deactivate')->table($part))
        ->assertNotified('Parça pasifleştirildi');
    expect($part->fresh()->is_active)->toBeFalse();

    Livewire::test(ListParts::class)
        ->filterTable('is_active', false)
        ->callAction(TestAction::make('activate')->table($part))
        ->assertNotified('Parça aktifleştirildi');
    expect($part->fresh()->is_active)->toBeTrue()
        ->and($this->user->can('delete', $part))->toBeFalse();
});

it('kritik filtre yalnızca bakiye <= kritik seviye ve seviye > 0 olanları gösterir', function () {
    $this->actingAs($this->user);
    $critical = Part::factory()->critical(10)->withStock(10)->create();
    $fine = Part::factory()->critical(10)->withStock(11)->create();
    $untracked = Part::factory()->critical(0)->create();

    Livewire::test(ListParts::class)
        ->filterTable('critical', true)
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$fine, $untracked]);
});

it('küsuratlı bakiyesi olan birimde küsurat kapatılamaz', function () {
    $unit = Unit::factory()->create(['allows_decimal' => true]);
    $part = Part::factory()->for($unit)->create();
    app(StockService::class)->stockIn($part, '2,5', $this->user);

    expect(fn () => app(UnitService::class)->update($unit, ['allows_decimal' => false], $unit->lock_version))
        ->toThrow(BusinessRuleException::class, 'küsurat kapatılamaz');

    app(StockService::class)->stockIn($part, '0,5', $this->user); // 3: tam sayı

    app(UnitService::class)->update($unit->fresh(), ['allows_decimal' => false], $unit->fresh()->lock_version);
    expect($unit->fresh()->allows_decimal)->toBeFalse();
});

it('birim düzenlemesi optimistic locking ile korunur', function () {
    $this->actingAs($this->user);
    $unit = Unit::factory()->create(['name' => 'kg']);

    $session = Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()]);
    app(UnitService::class)->update($unit, ['name' => 'kilogram'], 0);

    $session->fillForm(['name' => 'Kg'])->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($unit->fresh()->name)->toBe('kilogram');
    expect(fn () => app(UnitService::class)->update($unit->fresh(), ['name' => 'x'], 0))
        ->toThrow(StaleModelException::class);
});
