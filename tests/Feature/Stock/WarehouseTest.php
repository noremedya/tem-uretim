<?php

use App\Enums\PartType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Filament\Pages\StockOperation;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MotorModelService;
use App\Services\PartService;
use App\Services\StockService;
use App\Services\WarehouseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->warehouses = app(WarehouseService::class);
    $this->user = User::factory()->warehouse()->create();
    $this->raw = Warehouse::query()->where('code', 'HAM')->sole();
    $this->production = Warehouse::query()->where('code', 'URT')->sole();
    $this->part = Part::factory()->for(Unit::factory()->state(['name' => 'kg']))->create(['type' => PartType::RawMaterial]);
});

function qty(Part $part, Warehouse $warehouse): string
{
    return (string) (StockBalance::query()->where('part_id', $part->id)->where('warehouse_id', $warehouse->id)->value('quantity') ?? '0');
}

it('kurulumda dört depo açılır ve türler varsayılan depolarına eşlenir', function () {
    expect(Warehouse::query()->orderBy('id')->pluck('code')->all())->toBe(['HAM', 'URT', 'YM', 'MML'])
        ->and($this->warehouses->defaultIdForType(PartType::RawMaterial))->toBe($this->raw->id)
        ->and($this->warehouses->defaultIdForType(PartType::IndirectConsumable))->toBe($this->raw->id)
        ->and(Warehouse::find($this->warehouses->defaultIdForType(PartType::SemiFinished))->code)->toBe('YM')
        ->and(Warehouse::find($this->warehouses->defaultIdForType(PartType::FinishedGood))->code)->toBe('MML')
        ->and($this->warehouses->production()->code)->toBe('URT')
        ->and($this->warehouses->shipping()->code)->toBe('MML');
});

it('depo verilmeyen işlem ürünün varsayılan deposunda, verilen işlem o depoda yapılır', function () {
    $this->stock->stockIn($this->part, 10, $this->user);
    $movement = $this->stock->stockIn($this->part, 4, $this->user, warehouse: $this->production);

    expect(qty($this->part, $this->raw))->toBe('10.000')
        ->and(qty($this->part, $this->production))->toBe('4.000')
        ->and($movement->warehouse_id)->toBe($this->production->id)
        ->and($this->part->fresh('stockBalances')->totalStock())->toBe('14.000');
});

it('ürünün kendi varsayılan deposu türün deposundan önce gelir', function () {
    $part = app(PartService::class)->create([
        'code' => 'X-1', 'name' => 'Üretimde duran sarf', 'unit_id' => $this->part->unit_id,
        'type' => PartType::DirectConsumable, 'default_warehouse_id' => $this->production->id, 'critical_level' => '3',
    ]);

    $this->stock->stockIn($part, 5, $this->user);

    expect(qty($part, $this->production))->toBe('5.000')
        ->and(StockBalance::query()->where('part_id', $part->id)->where('warehouse_id', $this->production->id)->value('critical_level'))->toBe('3.000');
});

it('transfer kaynakta düşer, hedefte artırır; iki hareket aynı transfer kimliğini taşır', function () {
    $this->stock->stockIn($this->part, 10, $this->user);

    $movements = $this->stock->transfer($this->part, $this->raw, $this->production, '3,5', $this->user, 'Hatta besleme');

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('type')->unique()->all())->toBe([StockMovementType::Transfer])
        ->and($movements[0]->transfer_id)->not->toBeNull()->toBe($movements[1]->transfer_id)
        ->and((string) $movements[0]->quantity)->toBe('-3.500')
        ->and((string) $movements[1]->quantity)->toBe('3.500')
        ->and(qty($this->part, $this->raw))->toBe('6.500')
        ->and(qty($this->part, $this->production))->toBe('3.500');
});

it('transfer çift gönderimde tekrarlanmaz', function () {
    $this->stock->stockIn($this->part, 10, $this->user);
    $key = (string) Str::uuid();

    $first = $this->stock->transfer($this->part, $this->raw, $this->production, 2, $this->user, null, $key);
    $second = $this->stock->transfer($this->part, $this->raw, $this->production, 2, $this->user, null, $key);

    expect($second->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and($second[0]->wasRecentlyCreated)->toBeFalse()
        ->and(qty($this->part, $this->production))->toBe('2.000');
});

it('transfer kuralları: yetersiz kaynak, aynı depo, mamul ve ters kayıt reddedilir', function () {
    $this->stock->stockIn($this->part, 2, $this->user);

    expect(fn () => $this->stock->transfer($this->part, $this->raw, $this->production, 3, $this->user))
        ->toThrow(InsufficientStockException::class, 'Hammadde deposu')
        ->and(fn () => $this->stock->transfer($this->part, $this->raw, $this->raw, 1, $this->user))
        ->toThrow(BusinessRuleException::class, 'aynı olamaz');

    $finished = app(MotorModelService::class)->create(['code' => 'TM-71', 'name' => 'Motor'])->finishedPart;
    expect(fn () => $this->stock->transfer($finished, Warehouse::where('code', 'MML')->sole(), $this->production, 1, $this->user))
        ->toThrow(BusinessRuleException::class, 'mamul kartı');

    $movement = $this->stock->transfer($this->part, $this->raw, $this->production, 1, $this->user)->first();
    expect(fn () => $this->stock->correct($movement, $this->user, 'Geri al'))
        ->toThrow(BusinessRuleException::class, 'Transfer ters kaydedilemez')
        ->and(StockMovement::where('type', 'transfer')->count())->toBe(2);

    // Veritabanı da transfer kimliksiz transferi ve transferin ters kaydını reddeder.
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([
        'part_id' => $this->part->id, 'warehouse_id' => $this->production->id, 'type' => 'correction', 'quantity' => '-1',
        'balance_before' => '1', 'balance_after' => '0', 'corrected_movement_id' => $movement->id + 1,
        'description' => 'elle', 'user_id' => $this->user->id,
    ])))->toThrow(QueryException::class, 'Transfer ters kaydedilemez');
});

it('ters kayıt orijinal hareketin deposunda yapılır', function () {
    $in = $this->stock->stockIn($this->part, 4, $this->user, warehouse: $this->production);

    $correction = $this->stock->correct($in, $this->user, 'Yanlış depo');

    expect($correction->warehouse_id)->toBe($this->production->id)
        ->and(qty($this->part, $this->production))->toBe('0.000');
});

it('eksiye düşebilen depoda bakiye eksiye iner; diğer depolarda ve mamulde inemez', function () {
    $this->warehouses->update($this->production, ['allows_negative' => true], $this->production->lock_version);

    $this->stock->stockOut($this->part, 3, $this->user, 'Öne alınan sarf', warehouse: $this->production->fresh());

    expect(qty($this->part, $this->production))->toBe('-3.000')
        ->and(fn () => $this->stock->stockOut($this->part, 1, $this->user, 'Çıkış'))
        ->toThrow(InsufficientStockException::class);

    // Veritabanı da zorlar: izin verilmeyen depoda eksi bakiye yazılamaz.
    expect(fn () => DB::transaction(fn () => DB::table('stock_balances')
        ->where('part_id', $this->part->id)->where('warehouse_id', $this->raw->id)->update(['quantity' => -1])))
        ->toThrow(QueryException::class, 'eksiye düşemez');

    // Mamul eksiye düşebilen depoda da eksiye inemez.
    $finished = app(MotorModelService::class)->create(['code' => 'TM-80', 'name' => 'Motor'])->finishedPart;
    DB::table('stock_balances')->insertOrIgnore(['part_id' => $finished->id, 'warehouse_id' => $this->production->id, 'quantity' => 0, 'critical_level' => 0, 'is_below_critical' => false, 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('stock_balances')
        ->where('part_id', $finished->id)->where('warehouse_id', $this->production->id)->update(['quantity' => -1])))
        ->toThrow(QueryException::class, 'eksiye düşemez');

    // Eksi bakiye varken izin kapatılamaz.
    expect(fn () => $this->warehouses->update($this->production->fresh(), ['allows_negative' => false], $this->production->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'eksi bakiyeli');
});

it('eksiye düşebilen depoda üretim sarfı eksik malzemeyle de yapılır', function () {
    $this->warehouses->update($this->raw, ['allows_negative' => true], $this->raw->lock_version);
    $order = User::factory()->admin()->create();

    $movements = $this->stock->consume([['part' => $this->part, 'quantity' => 2]], $order, $this->user);

    expect($movements)->toHaveCount(1)->and(qty($this->part, $this->raw))->toBe('-2.000');
});

it('pasif depoya giriş yapılmaz; sayım ve dışarı transfer yapılır', function () {
    $extra = $this->warehouses->create(['code' => 'ek', 'name' => 'Ek depo']);
    $this->stock->stockIn($this->part, 5, $this->user, warehouse: $extra);

    expect(fn () => $this->warehouses->deactivate($extra))->toThrow(BusinessRuleException::class, 'stok var');

    $this->stock->transfer($this->part, $extra, $this->raw, 2, $this->user);
    $this->stock->adjustCount($this->part, 0, $this->user, 'Sayım', warehouse: $extra);
    $this->warehouses->deactivate($extra);
    $extra->refresh();

    expect($extra->code)->toBe('EK')
        ->and(fn () => $this->stock->stockIn($this->part, 1, $this->user, warehouse: $extra))
        ->toThrow(BusinessRuleException::class, 'pasif')
        ->and(fn () => $this->stock->transfer($this->part, $this->raw, $extra, 1, $this->user))
        ->toThrow(BusinessRuleException::class, 'pasif');

    $this->stock->adjustCount($this->part, 1, $this->user, 'Unutulan koli bulundu', warehouse: $extra);
    $this->stock->transfer($this->part, $extra, $this->raw, 1, $this->user);

    expect(qty($this->part, $extra))->toBe('0.000')->and(qty($this->part, $this->raw))->toBe('3.000');
});

it('varsayılan, üretim veya sevkiyat deposu pasifleştirilemez; her tür tek depoda varsayılandır', function () {
    expect(fn () => $this->warehouses->deactivate($this->production))->toThrow(BusinessRuleException::class, 'varsayılan depo')
        ->and(fn () => $this->warehouses->create(['code' => 'H2', 'name' => 'İkinci hammadde', 'default_part_types' => ['raw_material']]))
        ->toThrow(BusinessRuleException::class, 'Hammadde deposu');

    // Üretim deposu tektir: yeni depo işaretlenince eskisinden kaldırılır.
    $new = $this->warehouses->create(['code' => 'URT2', 'name' => 'Yeni üretim', 'is_production' => true]);

    expect($this->production->fresh()->is_production)->toBeFalse()
        ->and($this->warehouses->production()->is($new))->toBeTrue();
});

it('kritik seviye depo bazlıdır; bildirim depo adıyla bir kez gider', function () {
    $admin = User::factory()->admin()->create();
    $this->stock->stockIn($this->part, 10, $this->user);
    $this->stock->stockIn($this->part, 10, $this->user, warehouse: $this->production);
    $this->stock->setCriticalLevel($this->part, 5, $this->production);

    $this->stock->stockOut($this->part, 8, $this->user, 'Çıkış');                                   // HAM 2, takip yok
    $this->stock->stockOut($this->part, 6, $this->user, 'Çıkış', warehouse: $this->production);    // URT 4 ≤ 5
    $this->stock->stockOut($this->part, 1, $this->user, 'Çıkış', warehouse: $this->production);    // hâlâ kritik

    $notifications = $admin->notifications()->where('data->title', 'like', 'Kritik stok:%')->get();
    $part = $this->part->fresh('stockBalances');

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['body'])->toContain('Üretim deposu')
        ->and($part->isCritical())->toBeTrue()
        ->and($part->criticalLevel())->toBe('0.000')
        ->and(Part::query()->critical()->whereKey($this->part->id)->exists())->toBeTrue();
});

it('stock:reconcile depo bazında karşılaştırır', function () {
    User::factory()->admin()->create();
    $this->stock->stockIn($this->part, 5, $this->user);
    $this->stock->transfer($this->part, $this->raw, $this->production, 2, $this->user);

    $this->artisan('stock:reconcile')->assertSuccessful();

    // Toplam aynı kalsa da depolar arası kayma yakalanır.
    DB::table('stock_balances')->where('part_id', $this->part->id)->where('warehouse_id', $this->raw->id)->update(['quantity' => 2]);
    DB::table('stock_balances')->where('part_id', $this->part->id)->where('warehouse_id', $this->production->id)->update(['quantity' => 3]);

    $this->artisan('stock:reconcile')->assertFailed();
});

it('stok işlemi sayfasında depo seçilir ve transfer yapılır', function () {
    $this->actingAs($this->user);
    $this->stock->stockIn($this->part, 10, $this->user);

    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::Transfer->value, 'part_id' => $this->part->id])
        ->assertSchemaStateSet(['warehouse_id' => $this->raw->id])
        ->fillForm(['target_warehouse_id' => $this->production->id, 'quantity' => '4'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Transfer kaydedildi')
        ->assertSee('Üretim deposu 4 kg');

    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id])
        ->fillForm(['warehouse_id' => $this->production->id, 'quantity' => '1'])
        ->call('save')
        ->assertNotified('Giriş kaydedildi');

    expect(qty($this->part, $this->raw))->toBe('6.000')
        ->and(qty($this->part, $this->production))->toBe('5.000');
});

it('depo ekranını yönetici yönetir, diğer roller yalnızca görür', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateWarehouse::class)
        ->fillForm(['code' => 'kalite', 'name' => 'Kalite kontrol', 'allows_negative' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Warehouse::where('code', 'KALITE')->exists())->toBeTrue();

    $this->actingAs($this->user);
    Livewire::test(ListWarehouses::class)->assertOk()->assertSee('Hammadde deposu');
    $this->get(CreateWarehouse::getUrl())->assertForbidden();
});
