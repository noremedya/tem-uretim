<?php

use App\Exceptions\BusinessRuleException;
use App\Support\Money;

it('Türkçe tutar girişini normalize eder', function (string|int $input, string $expected, int $scale) {
    expect(Money::parse($input, $scale))->toBe($expected);
})->with([
    ['1.250,50', '1250.50', 2],
    ['1250,5', '1250.50', 2],
    ['10.000', '10000.00', 2],
    ['1.250.000', '1250000.00', 2],
    ['12.5', '12.50', 2],
    ['0.045', '0.0450', 4],
    ['10.000', '10000.0000', 4],
    [12, '12.00', 2],
    // Kurlarda nokta ondalıktır.
    ['41.987', '41.987000', 6],
    ['41,9876', '41.987600', 6],
]);

it('geçersiz tutarı ve fazla ondalığı reddeder', function (string $input) {
    expect(fn () => Money::parse($input))->toThrow(BusinessRuleException::class);
})->with(['abc', '1,234', '1.2.3', '']);

it('yarım yukarı yuvarlar; çarpım ve yüzde kuruşa yuvarlanır', function () {
    expect(Money::round('2.345'))->toBe('2.35')
        ->and(Money::round('2.344'))->toBe('2.34')
        ->and(Money::round('-2.345'))->toBe('-2.35')
        ->and(Money::mul('3', '33.335'))->toBe('100.01')
        ->and(Money::percent('100.01', '15'))->toBe('15.00')
        ->and(Money::percent('85.01', '20'))->toBe('17.00');
});
