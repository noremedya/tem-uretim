<?php

use App\Exceptions\BusinessRuleException;
use App\Support\Quantity;

it('Türkçe ve noktalı girdileri normalize eder', function (string|int|float $input, string $expected) {
    expect(Quantity::parse($input))->toBe($expected);
})->with([
    ['12', '12.000'],
    ['12,5', '12.500'],
    ['1.234,5', '1234.500'],
    ['12.5', '12.500'],
    [' 0,125 ', '0.125'],
    [7, '7.000'],
    [2.5, '2.500'],
    ['-3', '-3.000'],
]);

it('geçersiz girdiyi reddeder', function (string $input) {
    expect(Quantity::isValid($input))->toBeFalse()
        ->and(fn () => Quantity::parse($input))->toThrow(BusinessRuleException::class);
})->with(['', 'abc', '1,2345', '1e3', '12,5,1']);

it('tam sayı kontrolü ve Türkçe biçimlendirme', function () {
    expect(Quantity::isWhole('3.000'))->toBeTrue()
        ->and(Quantity::isWhole('3.001'))->toBeFalse()
        ->and(Quantity::format('1234.500', 'kg'))->toBe('1.234,5 kg')
        ->and(Quantity::format('5.000', signed: true))->toBe('+5')
        ->and(Quantity::format('-5.250'))->toBe('-5,25')
        ->and(Quantity::toInput('1234.500'))->toBe('1234,5')
        ->and(Quantity::toInput('10.000'))->toBe('10');
});
