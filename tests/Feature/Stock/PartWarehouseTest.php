<?php

use App\Enums\PartType;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\BomService;
use App\Services\PartService;
use App\Services\PartWarehouseService;
use App\Services\StockService;
use App\Services\WarehouseService;
use App\Services\WorkOrderService;
use Livewire\Livewire;

beforeEach(function () {
    config(['stock.part_warehouses' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->boms = app(BomService::class);
    $this->stock = app(StockService::class);
    $this->parent = Part::factory()->create(['code' => 'YM-GOV', 'name' => 'Gövde', 'type' => PartType::SemiFinished]);
    $this->raw = Part::factory()->create(['code' => 'hm-alu-1', 'name' => 'Alüminyum', 'type' => PartType::RawMaterial]);
});

function ownWarehouse(Part $part): ?Warehouse
{
    return Warehouse::query()->firstWhere('part_id', $part->id);
}

function partQty(Part $part, Warehouse $warehouse): string
{
    return (string) (StockBalance::query()->where('part_id', $part->id)->where('warehouse_id', $warehouse->id)->value('quantity') ?? '0');
}

it('ayar kapalıyken depo açılmaz', function () {
    config(['stock.part_warehouses' => false]);

    $this->boms->add($this->parent, $this->raw, '1');

    expect(Warehouse::query()->whereNotNull('part_id')->count())->toBe(0);
});

it('üst ürün ve bileşen reçeteye girince kendi deposunu alır, kod büyük harf', function () {
    $this->boms->add($this->parent, $this->raw, '1');

    expect(ownWarehouse($this->raw))->code->toBe('HM-ALU-1')->name->toBe('Alüminyum')
        ->and(ownWarehouse($this->parent)->code)->toBe('YM-GOV')
        ->and($this->raw->fresh()->default_warehouse_id)->toBe(ownWarehouse($this->raw)->id)
        ->and(app(WarehouseService::class)->defaultFor($this->raw->fresh())->code)->toBe('HM-ALU-1');
});

it('fire kartı ve eş ürün de depo alır, mamul almaz', function () {
    $scrap = Part::factory()->create(['type' => PartType::RawMaterial, 'unit_id' => $this->raw->unit_id]);
    $co = Part::factory()->create(['type' => PartType::SemiFinished]);
    $this->boms->add($this->parent, $this->raw, '1', 0, '0,1', $scrap);
    $this->boms->addCoProduct($this->parent, $co, '1');

    $motor = Part::factory()->create(['type' => PartType::FinishedGood]);
    $this->boms->add($motor, $this->parent, '1');

    expect(ownWarehouse($scrap))->not->toBeNull()
        ->and(ownWarehouse($co))->not->toBeNull()
        ->and(ownWarehouse($motor))->toBeNull();
});

it('mevcut stok tüm depolardan taşınır, kritik seviye gider, hareketler transfer olur', function () {
    $ham = Warehouse::query()->where('code', 'HAM')->sole();
    $urt = Warehouse::query()->where('code', 'URT')->sole();
    $this->stock->stockIn($this->raw, '10', $this->admin, warehouse: $ham);
    $this->stock->stockIn($this->raw, '5', $this->admin, warehouse: $urt);
    $this->stock->setCriticalLevel($this->raw, '3', $ham);

    $this->boms->add($this->parent, $this->raw, '1');
    $own = ownWarehouse($this->raw);

    expect(partQty($this->raw, $own))->toBe('15.000')
        ->and(partQty($this->raw, $ham))->toBe('0.000')
        ->and(partQty($this->raw, $urt))->toBe('0.000')
        ->and((string) StockBalance::query()->where('part_id', $this->raw->id)->where('warehouse_id', $own->id)->value('critical_level'))->toBe('3.000')
        ->and((string) StockBalance::query()->where('part_id', $this->raw->id)->where('warehouse_id', $ham->id)->value('critical_level'))->toBe('0.000');

    $this->artisan('stock:reconcile')->assertSuccessful();
});

it('kod başka depoda varsa çakışmaz, tekrar çalışınca yeni depo açılmaz', function () {
    $part = Part::factory()->create(['code' => 'HAM', 'type' => PartType::RawMaterial]);
    $service = app(PartWarehouseService::class);

    $service->ensure($part);
    $service->ensure($part->fresh());

    expect(ownWarehouse($part)->code)->toBe('HAM-URUN')
        ->and(Warehouse::query()->where('part_id', $part->id)->count())->toBe(1);
});

it('iş emri malzemeyi kendi deposundan düşer, malzeme deposu yok sayılır', function () {
    $this->boms->add($this->parent, $this->raw, '1');
    $this->stock->stockIn($this->raw->fresh(), '10', $this->admin);
    $order = app(WorkOrderService::class)->create(['product_part_id' => $this->parent->id, 'quantity' => 2, 'planned_end' => today()->addWeek()->toDateString()], $this->admin);

    expect($order->materials()->sole()->warehouse_id)->toBe(ownWarehouse($this->raw)->id)
        ->and($order->source_warehouse_id)->not->toBeNull();
});

it('açık iş emrinin malzeme satırı yeni depoya çevrilir', function () {
    config(['stock.part_warehouses' => false]);
    $this->boms->add($this->parent, $this->raw, '1');
    $order = app(WorkOrderService::class)->create(['product_part_id' => $this->parent->id, 'quantity' => 2, 'planned_end' => today()->addWeek()->toDateString()], $this->admin);
    config(['stock.part_warehouses' => true]);

    $this->artisan('warehouses:sync-parts')->assertSuccessful();

    expect(WorkOrderMaterial::query()->where('work_order_id', $order->id)->sole()->warehouse_id)->toBe(ownWarehouse($this->raw)->id)
        ->and(WorkOrder::query()->find($order->id)->status)->toBe(WorkOrderStatus::Planned);
});

it('toplu komut mevcut reçeteleri işler, alt tür depolarını kapatır, tekrar çalıştırılabilir', function () {
    config(['stock.part_warehouses' => false]);
    $this->boms->add($this->parent, $this->raw, '1');
    config(['stock.part_warehouses' => true]);

    $this->artisan('warehouses:sync-parts')->expectsOutputToContain('2 ürün deposu açıldı')->assertSuccessful();
    $this->artisan('warehouses:sync-parts')->expectsOutputToContain('0 ürün deposu açıldı')->assertSuccessful();

    expect(Warehouse::query()->where('code', 'ALU')->value('is_active'))->toBeFalse()
        ->and(Warehouse::query()->where('code', 'MIL')->value('is_active'))->toBeFalse();
});

it('ayar kapalıyken komut hata verir', function () {
    config(['stock.part_warehouses' => false]);

    $this->artisan('warehouses:sync-parts')->assertFailed();
});

it('pasif üründe depo açılır, stok taşınmaz', function () {
    $ham = Warehouse::query()->where('code', 'HAM')->sole();
    $this->stock->stockIn($this->raw, '4', $this->admin, warehouse: $ham);
    app(PartService::class)->deactivate($this->raw->fresh());

    app(PartWarehouseService::class)->ensure($this->raw);

    expect(ownWarehouse($this->raw))->not->toBeNull()
        ->and(partQty($this->raw, $ham))->toBe('4.000');
});

it('depo listesi ürün depolarını süzer', function () {
    $this->boms->add($this->parent, $this->raw, '1');

    Livewire::test(ListWarehouses::class)
        ->filterTable('part_id', false)
        ->assertCanNotSeeTableRecords([ownWarehouse($this->raw)])
        ->filterTable('part_id', true)
        ->assertCanSeeTableRecords([ownWarehouse($this->raw)]);
});
