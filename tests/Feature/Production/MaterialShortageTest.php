<?php

use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\ProductionService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

/*
 * İş emri → Malzemeler: eksik satırdan doğrudan stok girişi; yarı mamulde iş emri veya dışarıdan (fason) giriş.
 * Fikstür reçetesi (motor başına): 1 stator (yarı mamul), 2 rulman (komponent), 0,2 kg vernik (%5 fire). Stok yok.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->operator = User::factory()->operator()->create();
    $this->f = ProductionFixtures::create();
    $this->wo = $this->f->motorWorkOrder($this->admin, 10, ['assigned_user_id' => $this->operator->id, 'planned_start' => today()->addDay()]);
});

function materialsOf(WorkOrder $wo)
{
    return Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $wo, 'pageClass' => ViewWorkOrder::class]);
}

function materialFor(WorkOrder $wo, int $partId): WorkOrderMaterial
{
    return $wo->materials()->where('part_id', $partId)->sole();
}

it('eksik komponent için stok girişi: miktar eksik kadar gelir, bakiye artar, eksik kapanır', function () {
    $this->actingAs($this->admin);
    $bearing = materialFor($this->wo, $this->f->bearing->id);

    materialsOf($this->wo)
        ->mountAction(TestAction::make('resolveShortage')->table($bearing))
        ->assertSchemaStateSet(['quantity' => '20'], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Stok girişi kaydedildi: RUL-6202');

    $movement = StockMovement::query()->where('part_id', $this->f->bearing->id)->sole();
    expect($movement)->type->toBe(StockMovementType::StockIn)->quantity->toBe('20.000')
        ->description->toBe("{$this->wo->work_order_number} eksiği")
        ->idempotency_key->not->toBeNull();

    materialsOf($this->wo->fresh())->assertActionHidden(TestAction::make('resolveShortage')->table($bearing));
});

it('küsuratlı birimde eksik fire dahil hesaplanır ve girilen miktar kullanılır', function () {
    $this->actingAs($this->warehouse);
    $varnish = materialFor($this->wo, $this->f->varnish->id);

    materialsOf($this->wo)
        ->mountAction(TestAction::make('resolveShortage')->table($varnish))
        ->assertSchemaStateSet(['quantity' => '2,1'], 'mountedActionSchema0')
        ->setActionData(['quantity' => '5', 'description' => 'Acil alım'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->f->varnish->defaultStockBalance()->value('quantity'))->toBe('5.000')
        ->and(StockMovement::query()->where('part_id', $this->f->varnish->id)->value('description'))->toBe('Acil alım');
});

it('eksik yarı mamul için yönetici iş emri açar: adet eksik kadar, not bu iş emrinden, kendi partisiyle', function () {
    $this->actingAs($this->admin);
    $stator = materialFor($this->wo, $this->f->stator->id);

    materialsOf($this->wo)
        ->mountAction(TestAction::make('resolveShortage')->table($stator))
        ->assertSchemaStateSet(['mode' => 'work_order', 'quantity' => '10', 'planned_end' => today()->addDay()->toDateString()], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $created = WorkOrder::query()->where('product_part_id', $this->f->stator->id)->sole();
    expect($created)->status->toBe(WorkOrderStatus::Planned)->quantity->toBe('10.000')
        ->production_batch_id->not->toBe($this->wo->production_batch_id)
        ->notes->toBe("{$this->wo->work_order_number} için eksik yarı mamul")
        ->and(StockMovement::query()->where('part_id', $this->f->stator->id)->exists())->toBeFalse();
});

it('eksik yarı mamul dışarıdan (fason) geldiyse stok girişi yapılır', function () {
    $this->actingAs($this->admin);
    $stator = materialFor($this->wo, $this->f->stator->id);

    materialsOf($this->wo)
        ->callAction(TestAction::make('resolveShortage')->table($stator), ['mode' => 'stock_in', 'quantity' => '10'])
        ->assertHasNoActionErrors();

    expect(StockMovement::query()->where('part_id', $this->f->stator->id)->sole())
        ->quantity->toBe('10.000')
        ->description->toBe("Dışarıdan (fason) temin — {$this->wo->work_order_number} eksiği")
        ->and(WorkOrder::query()->where('product_part_id', $this->f->stator->id)->exists())->toBeFalse();
});

it('depo yarı mamulde yalnızca fason girişi yapabilir; iş emri açamaz', function () {
    $this->actingAs($this->warehouse);
    $stator = materialFor($this->wo, $this->f->stator->id);

    materialsOf($this->wo)
        ->mountAction(TestAction::make('resolveShortage')->table($stator))
        ->assertSchemaStateSet(['mode' => 'stock_in'], 'mountedActionSchema0')
        ->setActionData(['mode' => 'work_order', 'quantity' => '10', 'planned_end' => today()->toDateString()])
        ->callMountedAction();

    expect(WorkOrder::query()->where('product_part_id', $this->f->stator->id)->exists())->toBeFalse();
});

it('operatör ve tamamlanmış iş emrinde eksik tamamlama yoktur', function () {
    $bearing = materialFor($this->wo, $this->f->bearing->id);

    $this->actingAs($this->operator);
    materialsOf($this->wo)->assertActionHidden(TestAction::make('resolveShortage')->table($bearing));

    $this->actingAs($this->admin);
    $this->f->stockUp($this->admin);
    $completed = app(ProductionService::class)->complete($this->f->startedMotorWorkOrder($this->admin, 1), $this->admin);
    $this->f->stockUp($this->admin, '0');

    materialsOf($completed)->assertActionHidden(TestAction::make('resolveShortage')->table(materialFor($completed, $this->f->bearing->id)));
});
