<?php

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->user = User::factory()->operator()->create();
    // Aşama 4'teki iş emri yerine referans olarak herhangi bir morph'lu model yeterli.
    $this->reference = User::factory()->create();
});

it('birden fazla malzemeyi tek işlemde düşer; aynı parça satırları toplanır', function () {
    $copper = Part::factory()->withStock(10)->create();
    $bearing = Part::factory()->withStock(4)->create();

    $movements = $this->stock->consume([
        ['part' => $copper, 'quantity' => '2,5'],
        ['part' => $bearing->id, 'quantity' => 2],
        ['part' => $copper, 'quantity' => '1,5'],
    ], $this->reference, $this->user);

    expect($movements)->toHaveCount(2)
        ->and($movements->every(fn (StockMovement $m) => $m->type === StockMovementType::ProductionConsumption))->toBeTrue()
        ->and($movements->every(fn (StockMovement $m) => $m->reference_type === 'user' && $m->reference_id === $this->reference->id))->toBeTrue()
        ->and((string) $copper->stockBalance()->value('quantity'))->toBe('6.000')
        ->and((string) $bearing->stockBalance()->value('quantity'))->toBe('2.000');
});

it('herhangi bir malzeme yetersizse hiçbir şey değişmez ve tüm eksikler listelenir', function () {
    $enough = Part::factory()->withStock(10)->create(['code' => 'A-1']);
    $short1 = Part::factory()->withStock(1)->create(['code' => 'B-1']);
    $short2 = Part::factory()->create(['code' => 'C-1']);
    $count = StockMovement::count();

    try {
        $this->stock->consume([
            ['part' => $enough, 'quantity' => 5],
            ['part' => $short1, 'quantity' => 3],
            ['part' => $short2, 'quantity' => 2],
        ], $this->reference, $this->user);
        $this->fail('Exception bekleniyordu');
    } catch (InsufficientStockException $exception) {
        expect(collect($exception->shortages)->pluck('code')->all())->toBe(['B-1', 'C-1'])
            ->and(collect($exception->shortages)->pluck('missing')->all())->toBe(['2.000', '2.000'])
            ->and($exception->getMessage())->toContain('B-1')->toContain('C-1');
    }

    expect(StockMovement::count())->toBe($count)
        ->and((string) $enough->stockBalance()->value('quantity'))->toBe('10.000');
});

it('pasif parçadan üretim sarfı yapılamaz', function () {
    $part = Part::factory()->withStock(5)->inactive()->create();

    expect(fn () => $this->stock->consume([['part' => $part, 'quantity' => 1]], $this->reference, $this->user))
        ->toThrow(BusinessRuleException::class, 'yalnızca sayım');
});

it('üretim sarfı elle ters kaydedilemez', function () {
    $part = Part::factory()->withStock(5)->create();
    $movement = $this->stock->consume([['part' => $part, 'quantity' => 1]], $this->reference, $this->user)->first();

    expect(fn () => $this->stock->correct($movement, User::factory()->warehouse()->create(), 'x'))
        ->toThrow(BusinessRuleException::class, 'Üretim sarfı');
});
