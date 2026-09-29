<?php

use App\Services\NumberSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('sipariş numarası SIP-{YIL}-{5 hane} biçiminde ve sıralı üretilir', function () {
    $service = app(NumberSequenceService::class);
    $date = Carbon::parse('2026-05-10');

    expect($service->next('order', $date))->toBe('SIP-2026-00001')
        ->and($service->next('order', $date))->toBe('SIP-2026-00002')
        ->and($service->next('order', $date))->toBe('SIP-2026-00003');
});

it('sıra yıl başında sıfırlanır, önceki yılın sayacı etkilenmez', function () {
    $service = app(NumberSequenceService::class);

    $service->next('order', Carbon::parse('2026-12-31 23:59', 'Europe/Istanbul'));
    $service->next('order', Carbon::parse('2026-12-31 23:59', 'Europe/Istanbul'));

    expect($service->next('order', Carbon::parse('2027-01-01 00:01', 'Europe/Istanbul')))->toBe('SIP-2027-00001')
        ->and($service->next('order', Carbon::parse('2026-06-01')))->toBe('SIP-2026-00003');
});

it('yıl Türkiye saatine göre belirlenir', function () {
    // UTC'de 31 Aralık 22:30 = İstanbul'da 1 Ocak 01:30.
    expect(app(NumberSequenceService::class)->next('order', Carbon::parse('2026-12-31 22:30', 'UTC')))
        ->toBe('SIP-2027-00001');
});

it('geri alınan transaction numara tüketmez', function () {
    $service = app(NumberSequenceService::class);
    $date = Carbon::parse('2026-01-15');

    try {
        DB::transaction(function () use ($service, $date) {
            expect($service->next('order', $date))->toBe('SIP-2026-00001');

            throw new RuntimeException('işlem başarısız');
        });
    } catch (RuntimeException) {
    }

    expect($service->next('order', $date))->toBe('SIP-2026-00001');
});

it('ay ve bağlam belirteçlerini destekler; sayaç kapsamı sıra dışındaki kısımdır', function () {
    config(['numbering.serial_test' => '{MODEL_KODU}-{YY}{AA}-{SIRA:5}']);
    $service = app(NumberSequenceService::class);
    $may = Carbon::parse('2026-05-03');

    expect($service->next('serial_test', $may, ['MODEL_KODU' => 'M100']))->toBe('M100-2605-00001')
        ->and($service->next('serial_test', $may, ['MODEL_KODU' => 'M100']))->toBe('M100-2605-00002')
        // Farklı model ve farklı ay ayrı sayaçtır.
        ->and($service->next('serial_test', $may, ['MODEL_KODU' => 'M200']))->toBe('M200-2605-00001')
        ->and($service->next('serial_test', Carbon::parse('2026-06-01'), ['MODEL_KODU' => 'M100']))->toBe('M100-2606-00001');
});

it('sıra basamak sayısını aşınca numara uzar, kesilmez', function () {
    config(['numbering.short_test' => 'K{SIRA:1}']);
    $service = app(NumberSequenceService::class);

    foreach (range(1, 9) as $_) {
        $service->next('short_test');
    }

    expect($service->next('short_test'))->toBe('K10');
});

it('hatalı şablonlar reddedilir', function (string $template) {
    config(['numbering.bad' => $template]);

    expect(fn () => app(NumberSequenceService::class)->next('bad'))->toThrow(InvalidArgumentException::class);
})->with([
    'sıra yok' => 'SIP-{YIL}',
    'iki sıra' => '{SIRA:3}-{SIRA:3}',
    'bilinmeyen belirteç' => '{MODEL_KODU}-{SIRA:5}',
]);

it('tanımsız şablon reddedilir', function () {
    expect(fn () => app(NumberSequenceService::class)->next('yok'))->toThrow(InvalidArgumentException::class);
});

it('sayaç değeri veritabanında pozitif olmak zorundadır', function () {
    expect(fn () => DB::table('number_sequences')->insert(['name' => 'x', 'scope' => 'x', 'last_value' => 0]))
        ->toThrow(QueryException::class);
});
