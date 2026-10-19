<?php

use App\Filament\Pages\StockOperation;
use App\Filament\Resources\Parts\Pages\ViewPart;
use App\Filament\Resources\Parts\PartResource;
use App\Filament\Resources\Parts\RelationManagers\StockMovementsRelationManager;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use Livewire\Livewire;

it('yönetici tüm stok ekranlarına erişir', function () {
    $part = Part::factory()->withStock(3)->create();

    $this->actingAs(User::factory()->admin()->create());
    $this->get(PartResource::getUrl('index'))->assertOk()->assertSee($part->code);
    $this->get(PartResource::getUrl('create'))->assertOk();
    $this->get(PartResource::getUrl('view', ['record' => $part]))->assertOk();
    $this->get(PartResource::getUrl('edit', ['record' => $part]))->assertOk();
    $this->get(UnitResource::getUrl('index'))->assertOk();
    $this->get(UnitResource::getUrl('create'))->assertOk();
    $this->get(StockMovementResource::getUrl('index'))->assertOk();
    $this->get(StockOperation::getUrl())->assertOk();

    expect(StockMovementsRelationManager::canViewForRecord($part, ViewPart::class))->toBeTrue();
});

it('depo stokları ve hareketleri görür, stok işlemi yapar; kart ve birim açıp düzenleyemez', function () {
    $part = Part::factory()->withStock(3)->create();

    $this->actingAs(User::factory()->warehouse()->create());
    $this->get(PartResource::getUrl('index'))->assertOk()->assertSee($part->code);
    $this->get(PartResource::getUrl('view', ['record' => $part]))->assertOk();
    $this->get(StockMovementResource::getUrl('index'))->assertOk();
    $this->get(StockOperation::getUrl())->assertOk();
    $this->get(PartResource::getUrl('create'))->assertForbidden();
    $this->get(PartResource::getUrl('edit', ['record' => $part]))->assertForbidden();
    $this->get(UnitResource::getUrl('create'))->assertForbidden();

    Livewire::test(ViewPart::class, ['record' => $part->getRouteKey()])
        ->assertActionVisible('stockOperation')
        ->assertActionHidden('edit')
        ->assertActionHidden('deactivate');
});

it('operatör stokları ve hareketleri yalnızca görüntüler; stok işlemi yapamaz', function () {
    $operator = User::factory()->operator()->create();
    $part = Part::factory()->withStock(3)->create();

    $this->actingAs($operator);
    $this->get(PartResource::getUrl('index'))->assertOk()->assertSee($part->code);
    $this->get(PartResource::getUrl('view', ['record' => $part]))->assertOk();
    $this->get(StockMovementResource::getUrl('index'))->assertOk();
    $this->get(PartResource::getUrl('create'))->assertForbidden();
    $this->get(PartResource::getUrl('edit', ['record' => $part]))->assertForbidden();
    $this->get(UnitResource::getUrl('create'))->assertForbidden();
    $this->get(StockOperation::getUrl())->assertForbidden();

    expect(StockMovementsRelationManager::canViewForRecord($part, ViewPart::class))->toBeTrue();

    Livewire::test(ViewPart::class, ['record' => $part->getRouteKey()])
        ->assertActionHidden('stockOperation')
        ->assertActionHidden('edit')
        ->assertActionHidden('deactivate');
});

it('operatör stok işlemi sayfasından hareket oluşturamaz', function () {
    $this->actingAs(User::factory()->operator()->create());

    Livewire::test(StockOperation::class)->assertForbidden();
});

it('stok hareketi oluşturma/düzenleme/silme yetkisi kimsede yoktur', function () {
    $admin = User::factory()->admin()->create();
    $movement = Part::factory()->withStock(1)->create()->stockMovements()->sole();

    expect($admin->can('create', StockMovement::class))->toBeFalse()
        ->and($admin->can('update', $movement))->toBeFalse()
        ->and($admin->can('delete', $movement))->toBeFalse()
        ->and($admin->can('correct', $movement))->toBeTrue();
});
