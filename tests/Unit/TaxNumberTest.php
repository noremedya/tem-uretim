<?php

use App\Support\TaxNumber;

it('geçerli VKN ve TCKN kabul edilir', function (string $number) {
    expect(TaxNumber::isValid($number))->toBeTrue();
})->with([
    'VKN 1234567890' => '1234567890',
    'VKN 1111111114' => '1111111114',
    'VKN 9876543217' => '9876543217',
    'VKN baştaki sıfırlar 0012345672' => '0012345672',
    'VKN 0000000001' => '0000000001',
    'TCKN 10000000146 (yaygın test numarası)' => '10000000146',
    'TCKN 12345678950' => '12345678950',
    'TCKN 28710456160' => '28710456160',
]);

it('kontrol hanesi hatalı veya biçimi uygunsuz numaralar reddedilir', function (?string $number) {
    expect(TaxNumber::isValid($number))->toBeFalse();
})->with([
    'VKN son hane hatalı' => '1234567891',
    'VKN 1111111111' => '1111111111',
    'VKN 9876543210' => '9876543210',
    'TCKN ilk hane 0' => '01234567890',
    'TCKN 10. hane hatalı' => '10000000156',
    'TCKN 11. hane hatalı' => '10000000147',
    'TCKN 12345678901' => '12345678901',
    'TCKN 11111111111' => '11111111111',
    '9 hane' => '123456789',
    '12 hane' => '123456789012',
    'harf' => '12345678a0',
    'boş' => '',
    'null' => null,
]);

it('VKN kontrol hanesi her önek için tek ve tutarlıdır', function () {
    foreach (['000000000', '123456789', '999999999', '100000000'] as $prefix) {
        $valid = array_filter(range(0, 9), fn (int $d) => TaxNumber::isValid($prefix.$d));

        expect($valid)->toHaveCount(1)
            ->and(array_values($valid)[0])->toBe(TaxNumber::vknCheckDigit($prefix));
    }
});

it('TCKN için tek bir hane değişikliği her zaman yakalanır', function () {
    $valid = '28710456160';

    foreach (range(0, 10) as $position) {
        foreach (range(0, 9) as $digit) {
            if ((int) $valid[$position] === $digit) {
                continue;
            }

            expect(TaxNumber::isValid(substr_replace($valid, (string) $digit, $position, 1)))
                ->toBeFalse("{$position}. konumda {$digit}");
        }
    }
});
