<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Filament\RelationManagers\BomItemsRelationManager;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Filament\Resources\MotorModels\Pages\ViewMotorModel;
use App\Filament\Resources\Parts\Pages\ViewPart;
use App\Models\BomItem;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\BomService;
use App\Services\PartService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->boms = app(BomService::class);
    $this->kg = Unit::factory()->create(['name' => 'kg', 'allows_decimal' => true]);
    $this->piece = Unit::factory()->whole()->create(['name' => 'adet-test']);
});

function part(PartType $type, ?Unit $unit = null, array $attributes = []): Part
{
    return Part::factory()->create(['type' => $type, 'unit_id' => ($unit ?? test()->kg)->id, ...$attributes]);
}

it('çok seviyeli reçete kurulur: motor → stator → silisli sac + bakır', function () {
    $model = MotorModel::factory()->create();
    $stator = part(PartType::SemiFinished, $this->piece, ['code' => 'YM-STATOR']);
    $sheet = part(PartType::RawMaterial, null, ['code' => 'HM-SAC']);
    $copper = part(PartType::RawMaterial, null, ['code' => 'HM-BAKIR']);
    $bearing = part(PartType::Component, $this->piece);
    $varnish = part(PartType::DirectConsumable);

    $this->boms->add($stator, $sheet, '1,25', '3');
    $this->boms->add($stator, $copper, '0,8', '5');
    $this->boms->add($model->finishedPart, $stator, 1);
    $this->boms->add($model->finishedPart, $bearing, 2);
    $this->boms->add($model->finishedPart, $varnish, '0,2');

    expect($model->bomItems()->count())->toBe(3)
        ->and($stator->bomItems()->pluck('quantity_per_unit', 'component_part_id')->all())
        ->toBe([$sheet->id => '1.250', $copper->id => '0.800'])
        ->and(BomItem::where('component_part_id', $copper->id)->sole()->scrap_rate)->toBe('5.00');
});

it('reçete yalnızca yarı mamul veya mamul kartına tanımlanır', function () {
    $raw = part(PartType::RawMaterial);

    expect(fn () => $this->boms->add($raw, part(PartType::Component), 1))
        ->toThrow(BusinessRuleException::class, 'yalnızca yarı mamul veya mamul');
});

it('endirekt sarf, mamul ve pasif kart bileşen olamaz', function (Closure $component, string $message) {
    $semi = part(PartType::SemiFinished);

    expect(fn () => $this->boms->add($semi, $component(), 1))->toThrow(BusinessRuleException::class, $message);
})->with([
    'endirekt sarf' => [fn () => part(PartType::IndirectConsumable), 'Endirekt sarf reçeteye bileşen'],
    'mamul' => [fn () => MotorModel::factory()->create()->finishedPart, 'Mamul reçeteye bileşen'],
    'pasif' => [fn () => Part::factory()->inactive()->create(['type' => PartType::RawMaterial]), 'pasif'],
]);

it('tür kuralları veritabanında da zorlanır', function () {
    $semi = part(PartType::SemiFinished);
    $raw = part(PartType::RawMaterial);
    $indirect = part(PartType::IndirectConsumable);
    $insert = fn (int $parent, int $component) => DB::transaction(fn () => DB::table('bom_items')->insert([
        'parent_part_id' => $parent, 'component_part_id' => $component, 'quantity_per_unit' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    expect(fn () => $insert($raw->id, $semi->id))->toThrow(QueryException::class, 'yalnızca yarı mamul veya mamul')
        ->and(fn () => $insert($semi->id, $indirect->id))->toThrow(QueryException::class, 'Endirekt sarf ve mamul')
        ->and(fn () => $insert($semi->id, $semi->id))->toThrow(QueryException::class, 'bom_items_not_self_check');
});

it('döngü engellenir (doğrudan ve dolaylı)', function () {
    $a = part(PartType::SemiFinished, null, ['code' => 'A']);
    $b = part(PartType::SemiFinished, null, ['code' => 'B']);
    $c = part(PartType::SemiFinished, null, ['code' => 'C']);

    $this->boms->add($a, $b, 1);
    $this->boms->add($b, $c, 1);

    expect(fn () => $this->boms->add($b, $a, 1))->toThrow(BusinessRuleException::class, 'döngü')
        ->and(fn () => $this->boms->add($c, $a, 1))->toThrow(BusinessRuleException::class, 'döngü')
        ->and(fn () => $this->boms->add($a, $a, 1))->toThrow(BusinessRuleException::class, 'kendi reçetesine');

    // Döngü oluşturmayan ek bağlantı serbest.
    $this->boms->add($a, $c, 1);
    expect(BomItem::count())->toBe(3);
});

it('miktar, küsurat ve fire oranı doğrulanır; aynı bileşen iki kez eklenemez', function () {
    $semi = part(PartType::SemiFinished);
    $bolt = part(PartType::Component, $this->piece, ['code' => 'CIVATA']);

    expect(fn () => $this->boms->add($semi, $bolt, '1,5'))->toThrow(BusinessRuleException::class, 'tam sayı olmalıdır')
        ->and(fn () => $this->boms->add($semi, $bolt, 0))->toThrow(BusinessRuleException::class, 'sıfırdan büyük')
        ->and(fn () => $this->boms->add($semi, $bolt, 4, 100))->toThrow(BusinessRuleException::class, 'Fire oranı')
        ->and(fn () => $this->boms->add($semi, $bolt, 4, -1))->toThrow(BusinessRuleException::class, 'Fire oranı');

    $this->boms->add($semi, $bolt, 4, '2,5');

    expect(fn () => $this->boms->add($semi, $bolt, 2))->toThrow(BusinessRuleException::class, 'zaten var');
});

it('reçete satırı güncellenir (optimistic locking), kaldırılır ve denetim izine yazılır', function () {
    $this->actingAs($this->admin);
    $semi = part(PartType::SemiFinished);
    $item = $this->boms->add($semi, part(PartType::RawMaterial), 2);

    $this->boms->update($item, '2,5', 1, $item->lock_version);
    expect($item->fresh())->quantity_per_unit->toBe('2.500')->scrap_rate->toBe('1.00');

    expect(fn () => $this->boms->update(BomItem::find($item->id), 3, 1, 0))->toThrow(StaleModelException::class);

    $this->boms->remove($item->fresh());
    expect(BomItem::count())->toBe(0);

    expect(Activity::where('subject_type', 'bom_item')->where('subject_id', $item->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['created', 'updated', 'deleted']);
});

it('reçetesi olan veya reçetede kullanılan kartın türü reçeteyi bozacak şekilde değiştirilemez', function () {
    $parts = app(PartService::class);
    $semi = part(PartType::SemiFinished);
    $consumable = part(PartType::DirectConsumable);
    $this->boms->add($semi, $consumable, 1);

    expect(fn () => $parts->update($semi, ['type' => PartType::RawMaterial->value], $semi->lock_version))
        ->toThrow(BusinessRuleException::class, 'reçetesi var')
        ->and(fn () => $parts->update($consumable, ['type' => PartType::IndirectConsumable->value], $consumable->lock_version))
        ->toThrow(BusinessRuleException::class, 'reçetelerde bileşen olarak kullanılıyor');

    // Reçeteyi bozmayan değişiklik serbest.
    $parts->update($consumable->fresh(), ['type' => PartType::RawMaterial->value], $consumable->fresh()->lock_version);
    expect($consumable->fresh()->type)->toBe(PartType::RawMaterial);
});

it('yönetici motor modeli sayfasından reçeteye bileşen ekler', function () {
    $this->actingAs($this->admin);
    $model = MotorModel::factory()->create();
    $bearing = part(PartType::Component, $this->piece, ['code' => 'RUL-6202']);

    Livewire::test(BomItemsRelationManager::class, ['ownerRecord' => $model, 'pageClass' => ViewMotorModel::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'component_part_id' => $bearing->id, 'quantity_per_unit' => '2', 'scrap_rate' => '0',
        ])
        ->assertHasNoFormErrors();

    expect($model->bomItems()->sole()->component_part_id)->toBe($bearing->id);
});

it('iş kuralı ihlali ilişki yöneticisinde bildirim olarak gösterilir', function () {
    $this->actingAs($this->admin);
    $model = MotorModel::factory()->create();
    $indirect = part(PartType::IndirectConsumable);

    // Arama sonuçlarında çıkmasa da istek elle gönderilirse servis reddeder.
    Livewire::test(BomItemsRelationManager::class, ['ownerRecord' => $model, 'pageClass' => ViewMotorModel::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'component_part_id' => $indirect->id, 'quantity_per_unit' => '1', 'scrap_rate' => '0',
        ])
        ->assertNotified('İşlem yapılamadı');

    expect(BomItem::count())->toBe(0);
});

it('operatör ve depo reçeteyi göremez (motor modeli ve yarı mamul kartı)', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create());
    $model = MotorModel::factory()->create();

    expect(BomItemsRelationManager::canViewForRecord($model, ViewMotorModel::class))->toBeFalse()
        ->and(BomItemsRelationManager::canViewForRecord(part(PartType::SemiFinished), ViewPart::class))->toBeFalse();
})->with(['operator', 'warehouse']);

it('reçete sekmesi yalnızca yarı mamul kartında görünür', function () {
    $this->actingAs($this->admin);

    expect(BomItemsRelationManager::canViewForRecord(part(PartType::SemiFinished), ViewPart::class))->toBeTrue()
        ->and(BomItemsRelationManager::canViewForRecord(part(PartType::RawMaterial), ViewPart::class))->toBeFalse();
});

it('motor modeli sayfası reçete ve mamul stoğuyla açılır', function () {
    $this->actingAs($this->admin);
    $model = MotorModel::factory()->create();
    $this->boms->add($model->finishedPart, part(PartType::Component, $this->piece, ['code' => 'RUL-6203']), 2);

    $this->get(MotorModelResource::getUrl('view', ['record' => $model]))
        ->assertOk()
        ->assertSee('Mamul stoğu');

    Livewire::test(ViewMotorModel::class, ['record' => $model->getRouteKey()])
        ->assertSeeLivewire(BomItemsRelationManager::class);

    Livewire::test(BomItemsRelationManager::class, ['ownerRecord' => $model, 'pageClass' => ViewMotorModel::class])
        ->assertSee('RUL-6203');
});
