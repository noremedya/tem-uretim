<?php

use App\Enums\PartType;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\MotorBuilder;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Models\User;
use App\Services\BomTemplateService;
use App\Services\MotorBuilderService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->piece = Unit::factory()->whole()->create(['name' => 'adet-m']);
    $this->kg = Unit::factory()->create(['name' => 'kg-m', 'allows_decimal' => true]);
    $this->template = BomTemplate::query()->where('name', 'Trifaze asenkron motor')->sole();
});

function builderLine(string $label): BomTemplateLine
{
    return test()->template->lines()->where('label', $label)->sole();
}

function builderPart(string $type, string $subtype, ?Unit $unit = null, array $data = []): Part
{
    return Part::factory()->create([
        'type' => PartType::from($type),
        'subtype_id' => PartSubtype::query()->where('type', $type)->where('name', $subtype)->value('id'),
        'unit_id' => ($unit ?? test()->piece)->id,
        ...$data,
    ]);
}

it('motor modeli, mamul kartı ve reçete tek işlemde oluşur; aynı rulmanı seçen ön/arka satır toplanır', function () {
    $body = builderPart('semi_finished', 'Gövde', $this->kg);
    $stator = builderPart('semi_finished', 'Bobinajlı stator');
    $bearing = builderPart('component', 'Rulman', data: ['name' => 'Rulman 6202 2RS']);

    $result = app(MotorBuilderService::class)->build($this->template, ['code' => 'M71-TEST', 'name' => 'Deneme motoru', 'power_kw' => '0,37'], [
        builderLine('Gövde')->id => ['part_id' => $body->id, 'quantity_per_unit' => '2,35'],
        builderLine('Sargılı vernikli stator')->id => ['part_id' => $stator->id, 'quantity_per_unit' => '1'],
        builderLine('Ön rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '1'],
        builderLine('Arka rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '1'],
    ]);

    $finished = $result['model']->finishedPart;
    expect($result['added'])->toBe(3)
        ->and($result['model']->power_kw)->toBe('0.370')
        ->and($finished->bomItems()->where('component_part_id', $body->id)->sole()->quantity_per_unit)->toBe('2.350')
        ->and($finished->bomItems()->where('component_part_id', $bearing->id)->sole()->quantity_per_unit)->toBe('2.000');
});

it('bileşen seçilmezse, miktar geçersizse veya aynı ürün farklı fireyle seçilirse model de oluşmaz', function () {
    $bearing = builderPart('component', 'Rulman');
    $bolt = builderPart('direct_consumable', 'Bağlantı elemanı');
    $build = fn (array $rows) => app(MotorBuilderService::class)->build($this->template, ['code' => 'M-X', 'name' => 'X'], $rows);

    expect(fn () => $build([]))->toThrow(BusinessRuleException::class, 'Hiçbir satıra ürün seçilmedi')
        ->and(fn () => $build([builderLine('Ön rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '0']]))->toThrow(BusinessRuleException::class, 'Ön rulman: miktar sıfırdan büyük')
        ->and(fn () => $build([
            builderLine('Ön rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '1'],
            builderLine('Arka rulman')->id => ['part_id' => $bearing->id, 'quantity_per_unit' => '1', 'scrap_rate' => '5'],
        ]))->toThrow(BusinessRuleException::class, 'fireleri farklı')
        ->and(fn () => $build([builderLine('Civata')->id => ['part_id' => $bolt->id, 'quantity_per_unit' => '2,5']]))->toThrow(BusinessRuleException::class)
        ->and(MotorModel::query()->where('code', 'M-X')->exists())->toBeFalse()
        ->and(Part::query()->where('code', 'M-X')->exists())->toBeFalse();
});

it('rulman çeşidi adıyla aranır; yalnızca satırın tür ve alt türündeki aktif ürünler listelenir', function () {
    builderPart('component', 'Rulman', data: ['code' => 'KP-R-6202', 'name' => 'Rulman 6202 2RS']);
    builderPart('component', 'Rulman', data: ['code' => 'KP-R-6204', 'name' => 'Rulman 6204 ZZ']);
    builderPart('component', 'Rulman', data: ['code' => 'KP-R-6205', 'name' => 'Rulman 6205 ZZ', 'is_active' => false]);
    builderPart('component', 'Pervane', data: ['code' => 'KP-P-71', 'name' => 'Pervane 71 6204 uyumlu']);

    $service = app(BomTemplateService::class);

    expect(array_values($service->candidates(builderLine('Ön rulman'), search: '6204')))->toHaveCount(1)
        ->and(array_values($service->candidates(builderLine('Ön rulman'), search: '6204'))[0])->toContain('KP-R-6204')
        ->and($service->candidates(builderLine('Ön rulman')))->toHaveCount(2);
});

it('ekranı tüm roller kullanır: operatör motor oluşturur ama modeli düzenleyemez; yönetici oluşturunca modele gider', function () {
    $body = builderPart('semi_finished', 'Gövde', $this->kg, ['name' => 'Gövde 71 alüminyum']);
    $bearing = builderPart('component', 'Rulman', data: ['name' => 'Rulman 6202 2RS']);

    foreach (['operator', 'warehouse', 'admin'] as $role) {
        $this->actingAs(User::factory()->{$role}()->create());
        $this->get(MotorBuilder::getUrl())->assertOk();
    }
    $this->actingAs(User::factory()->withRoles(Role::Accounting)->create());
    $this->get(MotorBuilder::getUrl())->assertOk();

    $bodyLine = $this->template->lines()->where('label', 'Gövde')->value('id');
    $operator = User::factory()->operator()->create();
    $this->actingAs($operator);

    Livewire::test(MotorBuilder::class)
        ->fillForm(['template_id' => $this->template->id])
        ->assertSee('b3-trifaze.jpg')
        ->assertSee('01 · Gövde')
        ->fillForm(['code' => 'M71-OP', 'name' => 'Operatör motoru'])
        ->set("data.rows.{$bodyLine}.part_id", $body->id)
        ->set("data.rows.{$bodyLine}.quantity_per_unit", '2,4')
        ->assertSee('kg-m')
        ->set("data.rows.{$this->template->lines()->where('label', 'Ön rulman')->value('id')}.part_id", $bearing->id)
        ->callAction(TestAction::make('build')->schemaComponent('builder-actions', schema: 'content'))
        ->assertHasNoFormErrors()
        ->assertNotified('Motor oluşturuldu: M71-OP')
        ->assertNoRedirect();

    $model = MotorModel::query()->where('code', 'M71-OP')->sole();
    expect($model->finishedPart->bomItems()->count())->toBe(2);

    $this->get(MotorModelResource::getUrl('edit', ['record' => $model]))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(MotorBuilder::class)
        ->fillForm(['template_id' => $this->template->id])
        ->fillForm(['code' => 'M71-YN', 'name' => 'Yönetici motoru'])
        ->set("data.rows.{$this->template->lines()->where('label', 'Ön rulman')->value('id')}.part_id", $bearing->id)
        ->callAction(TestAction::make('build')->schemaComponent('builder-actions', schema: 'content'))
        ->assertRedirect(MotorModelResource::getUrl('view', ['record' => MotorModel::query()->where('code', 'M71-YN')->sole()]));
});

it('ürün seçilen satırda miktar zorunludur', function () {
    $this->actingAs(User::factory()->admin()->create());
    $line = $this->template->lines()->where('label', 'Gövde')->value('id');

    Livewire::test(MotorBuilder::class)
        ->fillForm(['template_id' => $this->template->id])
        ->fillForm(['code' => 'M-Q', 'name' => 'Q'])
        ->set("data.rows.{$line}.part_id", builderPart('semi_finished', 'Gövde', $this->kg)->id)
        ->callAction(TestAction::make('build')->schemaComponent('builder-actions', schema: 'content'))
        ->assertHasFormErrors(["rows.{$line}.quantity_per_unit" => 'required']);

    expect(MotorModel::query()->where('code', 'M-Q')->exists())->toBeFalse();
});
