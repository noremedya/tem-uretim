<?php

use App\Enums\Currency;
use App\Enums\ExchangeRateSource;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(ExchangeRateService::class);
});

function tcmbXml(string $date = '16.10.2026', string $usdSelling = '41.9876', string $eurSelling = '48.7654'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Tarih_Date Tarih="{$date}" Date="10/16/2026" Bulten_No="2026/198">
            <Currency CrossOrder="0" Kod="USD" CurrencyCode="USD">
                <Unit>1</Unit><Isim>ABD DOLARI</Isim><CurrencyName>US DOLLAR</CurrencyName>
                <ForexBuying>41.9120</ForexBuying><ForexSelling>{$usdSelling}</ForexSelling>
            </Currency>
            <Currency CrossOrder="9" Kod="EUR" CurrencyCode="EUR">
                <Unit>1</Unit><Isim>EURO</Isim><CurrencyName>EURO</CurrencyName>
                <ForexBuying>48.6800</ForexBuying><ForexSelling>{$eurSelling}</ForexSelling>
            </Currency>
        </Tarih_Date>
        XML;
}

it("TCMB'den EUR ve USD döviz alış/satış kurlarını çeker; aynı tarih tekrar kaydedilmez", function () {
    Http::fake(['*' => Http::response(tcmbXml())]);

    $this->artisan('exchange-rates:fetch')->expectsOutputToContain('16.10.2026 USD')->assertSuccessful();

    $usd = ExchangeRate::where('currency', 'USD')->sole();
    expect($usd)->forex_buying->toBe('41.912000')->forex_selling->toBe('41.987600')->source->toBe(ExchangeRateSource::Tcmb)
        ->and($usd->rate_date->toDateString())->toBe('2026-10-16')
        ->and(ExchangeRate::count())->toBe(2);

    $this->artisan('exchange-rates:fetch')->expectsOutput('Bu tarihin kurları zaten kayıtlı.')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(2);
});

it('internet yoksa uyarı verir, çökmez', function () {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
    Log::spy();

    $this->artisan('exchange-rates:fetch')->expectsOutputToContain('ulaşılamadı')->assertSuccessful();

    Log::shouldHaveReceived('warning')->once();
    expect(ExchangeRate::count())->toBe(0);
});

it('elle girilen kur TCMB kuruyla ezilmez; aynı tarihe ikinci elle kur girilemez', function () {
    $manual = $this->service->saveManual('USD', '2026-10-16', '41,5', '41,6', $this->admin);
    Http::fake(['*' => Http::response(tcmbXml())]);

    $this->service->fetchFromTcmb();

    expect($manual->fresh())->forex_selling->toBe('41.600000')->source->toBe(ExchangeRateSource::Manual)->user_id->toBe($this->admin->id)
        ->and(fn () => $this->service->saveManual('USD', '2026-10-16', 1, 1, $this->admin))->toThrow(BusinessRuleException::class, 'zaten var')
        ->and(fn () => $this->service->saveManual('TRY', today(), 1, 1, $this->admin))->toThrow(BusinessRuleException::class, 'TRY için kur girilmez')
        ->and(fn () => $this->service->saveManual('EUR', today(), 0, 1, $this->admin))->toThrow(BusinessRuleException::class, 'sıfırdan büyük');
});

it('kur düzeltmesi optimistic locking ile yapılır', function () {
    $rate = $this->service->saveManual('EUR', today(), '48', '49', $this->admin);
    $this->service->saveManual('EUR', today(), '48', '49,5', $this->admin, existing: $rate, expectedLockVersion: 0);

    expect(fn () => $this->service->saveManual('EUR', today(), '48', '50', $this->admin, existing: ExchangeRate::find($rate->id), expectedLockVersion: 0))
        ->toThrow(StaleModelException::class);
});

it('bir tarihin kuru o tarih ve öncesindeki en son kurdur; tür ayardan seçilir', function () {
    $this->service->saveManual('USD', '2026-10-14', '40', '41', $this->admin);
    $this->service->saveManual('USD', '2026-10-16', '42', '43', $this->admin);

    expect($this->service->rateFor(Currency::USD, Carbon::parse('2026-10-15')))->toBe('41.000000')
        ->and($this->service->rateFor(Currency::USD, Carbon::parse('2026-10-18')))->toBe('43.000000')
        ->and($this->service->rateFor(Currency::TRY, today()))->toBe('1.000000')
        ->and(fn () => $this->service->rateFor(Currency::USD, Carbon::parse('2026-10-01')))->toThrow(BusinessRuleException::class, 'kur yok')
        ->and(fn () => $this->service->rateFor(Currency::EUR, today()))->toThrow(BusinessRuleException::class, 'EUR için');

    config(['accounting.invoice_rate_type' => 'forex_buying']);
    expect($this->service->rateFor(Currency::USD, Carbon::parse('2026-10-18')))->toBe('42.000000');
});

it('iş günleri öğleden sonra saatlik zamanlanmıştır', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'exchange-rates:fetch'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * 1-5');
});

it('ekrandan kur elle girilir ve TCMB çekme butonu çalışır; depo erişemez', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateExchangeRate::class)
        ->fillForm(['currency' => 'EUR', 'rate_date' => today()->toDateString(), 'forex_buying' => '48,1234', 'forex_selling' => '48,9876'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ExchangeRate::sole()->forex_selling)->toBe('48.987600');

    Http::fake(fn () => throw new ConnectionException('offline'));
    Livewire::test(ListExchangeRates::class)->callAction('fetch')->assertNotified('TCMB kurlarına ulaşılamadı');

    $this->actingAs(User::factory()->warehouse()->create());
    $this->get(ExchangeRateResource::getUrl('index'))->assertForbidden();
});
