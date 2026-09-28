<?php

use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->warehouse()->create();
    $this->part = Part::factory()->withStock(10, $this->user)->create();
    $this->movement = StockMovement::where('part_id', $this->part->id)->sole();
});

it('model üzerinden güncellenemez ve silinemez', function () {
    $movement = $this->movement;

    expect(fn () => $movement->forceFill(['description' => 'değişti'])->save())
        ->toThrow(BusinessRuleException::class, 'değiştirilemez')
        ->and(fn () => $movement->delete())
        ->toThrow(BusinessRuleException::class, 'silinemez');

    expect($movement->fresh()->description)->toBe('Açılış stoğu');
});

it('veritabanında UPDATE, DELETE ve TRUNCATE trigger ile engellenir', function (string $sql) {
    expect(fn () => DB::transaction(fn () => DB::statement($sql)))
        ->toThrow(QueryException::class, 'Stok hareketleri değiştirilemez veya silinemez');

    expect(StockMovement::count())->toBe(1)
        ->and($this->movement->fresh()->description)->toBe('Açılış stoğu');
})->with([
    'update' => "UPDATE stock_movements SET description = 'x'",
    'delete' => 'DELETE FROM stock_movements',
    'truncate' => 'TRUNCATE stock_movements CASCADE',
]);

it('veritabanı hareket tutarlılığını check constraint ile zorlar', function (array $row, string $constraint) {
    $base = [
        'part_id' => $this->part->id, 'type' => 'stock_in', 'quantity' => '1',
        'balance_before' => '10', 'balance_after' => '11', 'user_id' => $this->user->id,
        'description' => 'x',
    ];

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert(array_merge($base, $row))))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'sıfır miktar' => [['quantity' => '0', 'balance_after' => '10'], 'stock_movements_quantity_check'],
    'bakiye hesabı' => [['balance_after' => '12'], 'stock_movements_balance_check'],
    'negatif bakiye' => [['type' => 'stock_out', 'quantity' => '-11', 'balance_after' => '-1'], 'stock_movements_balance_check'],
    'girişte eksi' => [['quantity' => '-1', 'balance_after' => '9'], 'stock_movements_type_sign_check'],
    'çıkışta artı' => [['type' => 'stock_out'], 'stock_movements_type_sign_check'],
    'ters kayıt bağlantısız' => [['type' => 'correction'], 'stock_movements_correction_check'],
    'çıkışta açıklama yok' => [['type' => 'stock_out', 'quantity' => '-1', 'balance_after' => '9', 'description' => null], 'stock_movements_description_check'],
]);
