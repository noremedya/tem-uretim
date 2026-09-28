<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->user = User::factory()->warehouse()->create();
});

function balanceOf(Part $part): string
{
    return (string) $part->stockBalance()->value('quantity');
}

it('giriş bakiyeyi artırır ve hareket kaydını doğru oluşturur', function () {
    $part = Part::factory()->create();

    $movement = $this->stock->stockIn($part, '12,5', $this->user, 'Fatura 123');

    expect($movement->fresh())
        ->type->toBe(StockMovementType::StockIn)
        ->quantity->toBe('12.500')
        ->balance_before->toBe('0.000')
        ->balance_after->toBe('12.500')
        ->description->toBe('Fatura 123')
        ->user_id->toBe($this->user->id)
        ->and(balanceOf($part))->toBe('12.500');
});

it('çıkış bakiyeyi azaltır ve eksi işaretli hareket yazar', function () {
    $part = Part::factory()->withStock(10)->create();

    $movement = $this->stock->stockOut($part, 3, $this->user, 'Tedarikçiye iade');

    expect($movement->fresh())
        ->type->toBe(StockMovementType::StockOut)
        ->quantity->toBe('-3.000')
        ->balance_before->toBe('10.000')
        ->balance_after->toBe('7.000')
        ->and(balanceOf($part))->toBe('7.000');
});

it('iade depoya dönüştür ve bakiyeyi artırır', function () {
    $part = Part::factory()->withStock(1)->create();

    $movement = $this->stock->returnToStock($part, 2, $this->user);

    expect($movement->quantity)->toBe('2.000')
        ->and(balanceOf($part))->toBe('3.000');
});

it('servis negatif stoğa izin vermez; hiçbir şey değişmez', function () {
    $part = Part::factory()->for(Unit::factory()->state(['name' => 'adet']))->withStock(5)->create();
    $movementCount = StockMovement::count();

    expect(fn () => $this->stock->stockOut($part, 6, $this->user, 'Deneme'))
        ->toThrow(InsufficientStockException::class, 'gereken 6 adet, mevcut 5 adet, eksik 1 adet');

    expect(balanceOf($part))->toBe('5.000')
        ->and(StockMovement::count())->toBe($movementCount);
});

it('veritabanı negatif bakiyeye izin vermez', function () {
    $part = Part::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('stock_balances')->where('part_id', $part->id)->update(['quantity' => -1])))
        ->toThrow(QueryException::class, 'stock_balances_quantity_check');
});

it('sıfır ve negatif miktarı reddeder', function (string $quantity) {
    $part = Part::factory()->create();

    expect(fn () => $this->stock->stockIn($part, $quantity, $this->user))
        ->toThrow(BusinessRuleException::class, 'Miktar sıfırdan büyük olmalıdır.');
})->with(['0', '-2']);

it('çıkış ve sayımda açıklama zorunludur', function () {
    $part = Part::factory()->withStock(5)->create();

    expect(fn () => $this->stock->stockOut($part, 1, $this->user, '  '))
        ->toThrow(BusinessRuleException::class, 'açıklama zorunludur')
        ->and(fn () => $this->stock->adjustCount($part, 4, $this->user, null))
        ->toThrow(BusinessRuleException::class, 'açıklama zorunludur');
});

it('sayım farkı hesaplar; fark yoksa hareket oluşturmaz', function () {
    $part = Part::factory()->withStock(10)->create();

    $down = $this->stock->adjustCount($part, 7, $this->user, 'Yıl sonu sayımı');
    expect($down->quantity)->toBe('-3.000')
        ->and($down->type)->toBe(StockMovementType::CountAdjustment)
        ->and(balanceOf($part))->toBe('7.000');

    $up = $this->stock->adjustCount($part, '9', $this->user, 'Tekrar sayım');
    expect($up->quantity)->toBe('2.000')->and(balanceOf($part))->toBe('9.000');

    $count = StockMovement::count();
    expect($this->stock->adjustCount($part, 9, $this->user, 'Aynı'))->toBeNull()
        ->and(StockMovement::count())->toBe($count);

    expect(fn () => $this->stock->adjustCount($part, -1, $this->user, 'Negatif'))
        ->toThrow(BusinessRuleException::class, 'negatif olamaz');
});

it('pasif parçaya yalnızca sayım yapılabilir', function () {
    $part = Part::factory()->withStock(10)->inactive()->create();

    expect(fn () => $this->stock->stockIn($part, 1, $this->user))
        ->toThrow(BusinessRuleException::class, 'yalnızca sayım')
        ->and(fn () => $this->stock->stockOut($part, 1, $this->user, 'x'))
        ->toThrow(BusinessRuleException::class, 'yalnızca sayım')
        ->and(fn () => $this->stock->returnToStock($part, 1, $this->user))
        ->toThrow(BusinessRuleException::class, 'yalnızca sayım');

    $movement = $this->stock->adjustCount($part, 8, $this->user, 'Pasif parça sayımı');
    expect($movement->quantity)->toBe('-2.000')->and(balanceOf($part))->toBe('8.000');
});

it('küsuratsız birimde küsuratlı miktar reddedilir (servis ve veritabanı)', function () {
    $part = Part::factory()->for(Unit::factory()->whole())->withStock(5)->create();

    expect(fn () => $this->stock->stockIn($part, '1,5', $this->user))
        ->toThrow(BusinessRuleException::class, 'tam sayı olmalıdır')
        ->and(fn () => $this->stock->adjustCount($part, '4,5', $this->user, 'Sayım'))
        ->toThrow(BusinessRuleException::class, 'tam sayı olmalıdır');

    expect(balanceOf($part))->toBe('5.000');

    // Veritabanı trigger'ı da reddeder.
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([
        'part_id' => $part->id, 'type' => 'stock_in', 'quantity' => '0.5',
        'balance_before' => '5', 'balance_after' => '5.5', 'user_id' => $this->user->id,
    ])))->toThrow(QueryException::class, 'küsuratlı miktar kabul etmez');

    // Küsuratlı birimde kabul edilir.
    $decimal = Part::factory()->for(Unit::factory())->create();
    $this->stock->stockIn($decimal, '1,5', $this->user);
    expect(balanceOf($decimal))->toBe('1.500');
});

it('aynı idempotency anahtarıyla ikinci hareket oluşmaz', function () {
    $part = Part::factory()->create();
    $key = (string) Str::uuid();

    $first = $this->stock->stockIn($part, 5, $this->user, null, $key);
    $second = $this->stock->stockIn($part, 5, $this->user, null, $key);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(StockMovement::where('part_id', $part->id)->count())->toBe(1)
        ->and(balanceOf($part))->toBe('5.000');
});

it('bakiye satırı lockForUpdate ile kilitlenir', function () {
    $part = Part::factory()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->stock->stockIn($part, 1, $this->user);

    expect(collect($queries)->first(fn ($sql) => str_contains($sql, 'from "stock_balances"')))
        ->toContain('for update');
});
