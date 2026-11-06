<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Filament\Resources\MotorDesigns\Pages\CreateMotorDesign;
use App\Filament\Resources\MotorDesigns\Pages\EditMotorDesign;
use App\Filament\Resources\MotorDesigns\RelationManagers\ItemsRelationManager;
use App\Models\MotorDesign;
use App\Models\MotorDesignItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MotorDesignService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ProductionFixtures;

/*
 * Tasarımlar (deneme reçetesi): stoktan düşmez, iş emri açılamaz; bileşenler serbestçe eklenip kaldırılır.
 */

beforeEach(function () {
    // Modül gizli (config/features.php); davranışı açıkken test edilir.
    config(['features.motor_designs' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->f = ProductionFixtures::create();
    $this->service = app(MotorDesignService::class);
    $this->design = $this->service->create(['code' => ' tsr-01 ', 'name' => 'Deneme motoru']);
});

it('tasarım kodu büyük harfe çevrilir ve tekildir', function () {
    expect($this->design->code)->toBe('TSR-01')
        ->and(fn () => $this->service->create(['code' => 'TSR-01', 'name' => 'Başka']))->toThrow(BusinessRuleException::class, 'zaten var');
});

it('ürün ve deneme bileşeni eklenir, değiştirilir, kaldırılır; stok ve iş emri etkilenmez', function () {
    $balance = (string) $this->f->bearing->defaultStockBalance()->value('quantity');
    $movements = StockMovement::query()->count();
    $bearing = $this->service->addItem($this->design, ['part_id' => $this->f->bearing->id, 'quantity' => '2']);
    $trial = $this->service->addItem($this->design, ['description' => 'Yeni fan (numune)', 'unit_id' => $this->f->piece->id, 'quantity' => '1', 'notes' => 'Numune']);

    $this->service->updateItem($bearing, ['part_id' => $this->f->bearing->id, 'quantity' => '3']);
    expect($bearing->refresh()->quantity)->toBe('3.000')
        ->and($trial->label())->toBe('Yeni fan (numune)')
        ->and($trial->unitName())->toBe($this->f->piece->name);

    $this->service->removeItem($trial);

    expect(MotorDesignItem::query()->pluck('id')->all())->toBe([$bearing->id])
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and((string) $this->f->bearing->defaultStockBalance()->value('quantity'))->toBe($balance)
        ->and(WorkOrder::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', 'motor_design_item')->where('event', 'deleted')->exists())->toBeTrue();
});

it('kurallar: ürün veya ad (biri), mamul olmaz, aynı ürün iki kez olmaz, küsurat ve pozitif miktar', function (array $data, string $message) {
    $this->service->addItem($this->design, ['part_id' => $this->f->bearing->id, 'quantity' => '2']);

    $data = array_map(fn ($v) => $v instanceof Closure ? $v($this->f) : $v, $data);

    expect(fn () => $this->service->addItem($this->design, $data))->toThrow(BusinessRuleException::class, $message);
})->with([
    'ikisi birden' => [['part_id' => fn ($f) => $f->varnish->id, 'description' => 'X', 'quantity' => '1'], 'yalnızca biri'],
    'hiçbiri' => [['quantity' => '1'], 'yalnızca biri'],
    'mamul' => [['part_id' => fn ($f) => $f->model->finished_part_id, 'quantity' => '1'], 'eklenemez'],
    'tekrar' => [['part_id' => fn ($f) => $f->bearing->id, 'quantity' => '1'], 'zaten var'],
    'küsurat' => [['part_id' => fn ($f) => $f->stator->id, 'quantity' => '1,5'], 'tam sayı'],
    'sıfır' => [['part_id' => fn ($f) => $f->varnish->id, 'quantity' => '0'], 'sıfırdan büyük'],
    'birimsiz deneme' => [['description' => 'Numune', 'quantity' => '1'], 'birimi zorunlu'],
]);

it('pasif tasarımın bileşenleri değişmez', function () {
    $this->service->deactivate($this->design);

    expect(fn () => $this->service->addItem($this->design, ['part_id' => $this->f->bearing->id, 'quantity' => '1']))
        ->toThrow(BusinessRuleException::class, 'Pasif tasarım');
});

it('veritabanı da satır türünü ve miktarı zorlar', function () {
    $insert = fn (array $row) => DB::table('motor_design_items')->insert([
        'motor_design_id' => $this->design->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(), ...$row,
    ]);

    expect(fn () => $insert(['part_id' => null, 'description' => 'X']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['part_id' => $this->f->bearing->id, 'unit_id' => $this->f->piece->id]))->toThrow(QueryException::class)
        ->and(fn () => $insert(['part_id' => $this->f->bearing->id, 'quantity' => 0]))->toThrow(QueryException::class);
});

it('yönetici ekranda tasarım açar ve bileşen ekleyip kaldırır', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateMotorDesign::class)
        ->fillForm(['code' => 'TSR-02', 'name' => 'İkinci deneme'])
        ->call('create')
        ->assertHasNoFormErrors();

    $design = MotorDesign::query()->where('code', 'TSR-02')->sole();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $design, 'pageClass' => EditMotorDesign::class])
        ->callAction(TestAction::make('addItem')->table(), data: ['kind' => 'part', 'part_id' => $this->f->bearing->id, 'quantity' => '2'])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('addItem')->table(), data: ['kind' => 'trial', 'description' => 'Numune fan', 'unit_id' => $this->f->piece->id, 'quantity' => '1'])
        ->assertHasNoFormErrors()
        ->assertSee('Numune fan');

    $trial = $design->items()->whereNull('part_id')->sole();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $design, 'pageClass' => EditMotorDesign::class])
        ->callAction(TestAction::make('delete')->table($trial));

    expect($design->items()->count())->toBe(1);
});

it('muhasebe görür ama değiştiremez; operatör ve depo göremez', function () {
    $accountant = User::factory()->withRoles(Role::Accounting)->create();
    $this->actingAs($accountant);

    $this->get(MotorDesignResource::getUrl('index'))->assertOk();
    $this->get(MotorDesignResource::getUrl('view', ['record' => $this->design]))->assertOk();
    $this->get(MotorDesignResource::getUrl('edit', ['record' => $this->design]))->assertForbidden();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->design, 'pageClass' => EditMotorDesign::class])
        ->assertActionHidden(TestAction::make('addItem')->table());

    foreach ([User::factory()->operator()->create(), User::factory()->warehouse()->create()] as $user) {
        $this->actingAs($user);
        $this->get(MotorDesignResource::getUrl('index'))->assertForbidden();
    }
});

it('modül gizliyken menüde görünmez ve sayfaları yöneticiye de kapalıdır', function () {
    config(['features.motor_designs' => false]);
    $this->actingAs($this->admin);

    expect(MotorDesignResource::canAccess())->toBeFalse();
    $this->get(MotorDesignResource::getUrl('index'))->assertForbidden();
    $this->get(MotorDesignResource::getUrl('view', ['record' => $this->design]))->assertForbidden();
});
