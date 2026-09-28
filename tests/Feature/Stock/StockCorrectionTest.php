<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->user = User::factory()->warehouse()->create();
    $this->part = Part::factory()->create();
});

it('ters kayıt orijinalin tam tersi miktarla yapılır ve bakiyeyi geri alır', function () {
    $in = $this->stock->stockIn($this->part, '7,5', $this->user);

    $correction = $this->stock->correct($in, $this->user, 'Yanlış parçaya girilmiş');

    expect($correction->fresh())
        ->type->toBe(StockMovementType::Correction)
        ->quantity->toBe('-7.500')
        ->corrected_movement_id->toBe($in->id)
        ->balance_after->toBe('0.000')
        ->description->toBe('Yanlış parçaya girilmiş')
        ->and($in->fresh()->correction->id)->toBe($correction->id)
        ->and((string) $this->part->stockBalance()->value('quantity'))->toBe('0.000');
});

it('çıkışın ters kaydı stoğu geri artırır', function () {
    $this->stock->stockIn($this->part, 10, $this->user);
    $out = $this->stock->stockOut($this->part, 4, $this->user, 'Yanlış çıkış');

    $correction = $this->stock->correct($out, $this->user, 'Düzeltme');

    expect($correction->quantity)->toBe('4.000')
        ->and($correction->balance_after)->toBe('10.000');
});

it('bir hareket yalnızca bir kez ters kaydedilebilir (servis)', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $this->stock->stockIn($this->part, 5, $this->user);
    $this->stock->correct($in, $this->user, 'İlk düzeltme');

    expect(fn () => $this->stock->correct($in->fresh(), $this->user, 'İkinci düzeltme'))
        ->toThrow(BusinessRuleException::class, 'zaten ters kaydedilmiş');

    expect(StockMovement::where('corrected_movement_id', $in->id)->count())->toBe(1)
        ->and((string) $this->part->stockBalance()->value('quantity'))->toBe('5.000');
});

it('bir hareket yalnızca bir kez ters kaydedilebilir (veritabanı unique)', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $this->stock->stockIn($this->part, 5, $this->user);
    $this->stock->correct($in, $this->user, 'İlk düzeltme');

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([
        'part_id' => $this->part->id, 'type' => 'correction', 'quantity' => '-5',
        'balance_before' => '5', 'balance_after' => '0', 'corrected_movement_id' => $in->id,
        'description' => 'elle', 'user_id' => $this->user->id,
    ])))->toThrow(QueryException::class, 'stock_movements_corrected_movement_id_unique');
});

it('ters kaydın kendisi ters kaydedilemez (servis ve veritabanı)', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $correction = $this->stock->correct($in, $this->user, 'Düzeltme');

    expect(fn () => $this->stock->correct($correction, $this->user, 'Geri al'))
        ->toThrow(BusinessRuleException::class, 'Ters kaydın kendisi ters kaydedilemez.');

    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->insert([
        'part_id' => $this->part->id, 'type' => 'correction', 'quantity' => '5',
        'balance_before' => '0', 'balance_after' => '5', 'corrected_movement_id' => $correction->id,
        'description' => 'elle', 'user_id' => $this->user->id,
    ])))->toThrow(QueryException::class, 'Ters kaydın kendisi ters kaydedilemez.');
});

it('veritabanı ters kaydın tam ters miktarda ve aynı parçada olmasını zorlar', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $other = Part::factory()->withStock(10)->create();

    $insert = fn (array $row) => fn () => DB::transaction(fn () => DB::table('stock_movements')->insert(array_merge([
        'part_id' => $this->part->id, 'type' => 'correction', 'quantity' => '-5',
        'balance_before' => '5', 'balance_after' => '0', 'corrected_movement_id' => $in->id,
        'description' => 'elle', 'user_id' => $this->user->id,
    ], $row)));

    expect($insert(['quantity' => '-4', 'balance_after' => '1']))
        ->toThrow(QueryException::class, 'tam ters miktarda')
        ->and($insert(['part_id' => $other->id, 'balance_before' => '10', 'balance_after' => '5']))
        ->toThrow(QueryException::class, 'tam ters miktarda');
});

it('stoğu eksiye düşürecek ters kayıt reddedilir; hiçbir şey değişmez', function () {
    $in = $this->stock->stockIn($this->part, 10, $this->user);
    $this->stock->stockOut($this->part, 8, $this->user, 'Üretime verildi');

    expect(fn () => $this->stock->correct($in, $this->user, 'Girişi geri al'))
        ->toThrow(InsufficientStockException::class);

    expect((string) $this->part->stockBalance()->value('quantity'))->toBe('2.000')
        ->and($in->correction()->exists())->toBeFalse();
});

it('ters kayıtta açıklama zorunludur', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);

    expect(fn () => $this->stock->correct($in, $this->user, ''))
        ->toThrow(BusinessRuleException::class, 'açıklama zorunludur');
});

it('pasif parçanın hareketi ters kaydedilemez', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $this->part->is_active = false;
    $this->part->save();

    expect(fn () => $this->stock->correct($in, $this->user, 'Düzeltme'))
        ->toThrow(BusinessRuleException::class, 'yalnızca sayım');
});

it('hareket listesinden ters kayıt yapılır; ters kaydedilmiş harekette aksiyon gizlenir', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);

    $this->actingAs($this->user);

    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('correct')->table($in), ['description' => 'Yanlış giriş'])
        ->assertHasNoFormErrors()
        ->assertNotified('Ters kayıt oluşturuldu');

    $correction = $in->fresh()->correction;
    expect($correction)->not->toBeNull()
        ->and($correction->quantity)->toBe('-5.000');

    Livewire::test(ListStockMovements::class)
        ->assertActionHidden(TestAction::make('correct')->table($in->fresh()))
        ->assertActionHidden(TestAction::make('correct')->table($correction));
});

it('ters kayıt formunda açıklama zorunludur', function () {
    $in = $this->stock->stockIn($this->part, 5, $this->user);
    $this->actingAs($this->user);

    Livewire::test(ListStockMovements::class)
        ->callAction(TestAction::make('correct')->table($in), ['description' => ''])
        ->assertHasFormErrors(['description' => 'required']);

    expect($in->correction()->exists())->toBeFalse();
});
