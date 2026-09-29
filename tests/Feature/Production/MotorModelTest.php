<?php

use App\Enums\MotorPhase;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Filament\Resources\MotorModels\Pages\CreateMotorModel;
use App\Filament\Resources\MotorModels\Pages\EditMotorModel;
use App\Filament\Resources\MotorModels\Pages\ListMotorModels;
use App\Filament\Resources\MotorModels\Pages\ViewMotorModel;
use App\Models\MotorModel;
use App\Models\User;
use App\Services\MotorModelService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('formdan motor modeli tüm teknik alanlarıyla oluşturulur ve denetim izine yazılır', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateMotorModel::class)
        ->fillForm([
            'code' => ' TM-112 ', 'name' => 'Trifaze 5,5 kW', 'power_kw' => '5,5', 'speed_rpm' => '1450',
            'voltage' => '230/400 V', 'frequency_hz' => '50', 'pole_count' => '4', 'frame_size' => '112M',
            'phase' => MotorPhase::ThreePhase->value, 'mounting_type' => 'B3', 'protection_class' => 'IP55',
            'efficiency_class' => 'IE3', 'description' => 'Standart seri',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $model = MotorModel::where('code', 'TM-112')->sole();

    expect($model)
        ->power_kw->toBe('5.500')
        ->speed_rpm->toBe(1450)
        ->frequency_hz->toBe(50)
        ->pole_count->toBe(4)
        ->phase->toBe(MotorPhase::ThreePhase)
        ->mounting_type->toBe('B3')
        ->is_active->toBeTrue()
        ->and(Activity::where('subject_type', 'motor_model')->where('subject_id', $model->id)->exists())->toBeTrue();

    $this->get(MotorModelResource::getUrl('view', ['record' => $model]))
        ->assertOk()->assertSee('5,5 kW')->assertSee('1.450 d/dk')->assertSee('Trifaze');
});

it('yalnızca kod ve ad zorunludur; boş teknik alanlar null kalır', function () {
    $model = app(MotorModelService::class)->create([
        'code' => 'X1', 'name' => 'Sade', 'power_kw' => '', 'speed_rpm' => null, 'voltage' => ' ',
    ]);

    expect($model->fresh())->power_kw->toBeNull()->speed_rpm->toBeNull()->voltage->toBeNull()->phase->toBeNull();
});

it('geçersiz teknik değerler formda reddedilir', function (string $field, string $value) {
    $this->actingAs($this->admin);

    Livewire::test(CreateMotorModel::class)
        ->fillForm(['code' => 'X', 'name' => 'X', $field => $value])
        ->call('create')
        ->assertHasFormErrors([$field]);
})->with([
    ['power_kw', '0'],
    ['power_kw', 'abc'],
    ['speed_rpm', '0'],
    ['speed_rpm', '12,5'],
    ['pole_count', '-2'],
]);

it('servis de geçersiz teknik değerleri reddeder', function () {
    expect(fn () => app(MotorModelService::class)->create(['code' => 'X', 'name' => 'X', 'power_kw' => '-1']))
        ->toThrow(BusinessRuleException::class, 'Güç')
        ->and(fn () => app(MotorModelService::class)->create(['code' => 'X', 'name' => 'X', 'speed_rpm' => '1,5']))
        ->toThrow(BusinessRuleException::class, 'Devir');
});

it('veritabanı kod tekilliğini, faz değerini ve pozitif değerleri zorlar', function (array $attributes) {
    MotorModel::factory()->create(['code' => 'DUP']);

    expect(fn () => DB::table('motor_models')->insert(array_merge(['code' => 'NEW', 'name' => 'n'], $attributes)))
        ->toThrow(QueryException::class);
})->with([
    'aynı kod' => [['code' => 'DUP']],
    'geçersiz faz' => [['phase' => 'two_phase']],
    'sıfır güç' => [['power_kw' => 0]],
    'negatif devir' => [['speed_rpm' => -1]],
    'boş kod' => [['code' => '  ']],
]);

it('motor modeli düzenlemesi iki ayrı oturumda çakışırsa ikincisi reddedilir', function () {
    $model = MotorModel::factory()->create(['name' => 'Eski']);
    $other = User::factory()->admin()->create();

    $this->actingAs($this->admin);
    $sessionA = Livewire::test(EditMotorModel::class, ['record' => $model->getRouteKey()]);
    $this->actingAs($other);
    $sessionB = Livewire::test(EditMotorModel::class, ['record' => $model->getRouteKey()]);

    $this->actingAs($this->admin);
    $sessionA->fillForm(['name' => 'A adı'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($other);
    $sessionB->fillForm(['name' => 'B adı'])->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($model->fresh())->name->toBe('A adı')->lock_version->toBe(1);
});

it('motor modeli pasifleştirilir ve aktifleştirilir; silinemez', function () {
    $this->actingAs($this->admin);
    $model = MotorModel::factory()->create();

    Livewire::test(ListMotorModels::class)
        ->callAction(TestAction::make('deactivate')->table($model))
        ->assertNotified('Motor modeli pasifleştirildi');
    expect($model->fresh()->is_active)->toBeFalse();

    Livewire::test(ListMotorModels::class)
        ->filterTable('is_active', false)
        ->callAction(TestAction::make('activate')->table($model))
        ->assertNotified('Motor modeli aktifleştirildi');
    expect($model->fresh()->is_active)->toBeTrue()
        ->and($this->admin->can('delete', $model))->toBeFalse();
});

it('operatör ve depo motor modellerini yalnızca görüntüler', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $model = MotorModel::factory()->create();

    $this->actingAs($user);
    $this->get(MotorModelResource::getUrl('index'))->assertOk()->assertSee($model->code);
    $this->get(MotorModelResource::getUrl('view', ['record' => $model]))->assertOk();
    $this->get(MotorModelResource::getUrl('create'))->assertForbidden();
    $this->get(MotorModelResource::getUrl('edit', ['record' => $model]))->assertForbidden();

    Livewire::test(ViewMotorModel::class, ['record' => $model->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('deactivate');
})->with(['operator', 'warehouse']);
