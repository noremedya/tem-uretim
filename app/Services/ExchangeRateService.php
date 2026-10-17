<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\ExchangeRateSource;
use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Döviz kurları (CLAUDE.md bölüm 5 "Cari, döviz ve fiyat"). Kurlar internet varken TCMB'den çekilir;
 * internet yoksa elle girilir. Elle girilen kur TCMB kuruyla ezilmez.
 */
class ExchangeRateService
{
    /**
     * TCMB today.xml'den EUR ve USD döviz alış/satış kurlarını çeker. İnternet yoksa veya yanıt bozuksa
     * uyarı loglar ve null döner (çökmez).
     *
     * @return list<ExchangeRate>|null Kaydedilen kurlar (o tarih zaten varsa boş liste).
     */
    public function fetchFromTcmb(): ?array
    {
        try {
            $response = Http::timeout(15)->get(config('accounting.tcmb_rates_url'))->throw();
            $xml = new SimpleXMLElement($response->body());
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('exchange-rates:fetch: TCMB kurlarına ulaşılamadı (internet yok olabilir).', ['error' => $exception->getMessage()]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('exchange-rates:fetch: TCMB yanıtı okunamadı.', ['error' => $exception->getMessage()]);

            return null;
        }

        $date = CarbonImmutable::createFromFormat('!d.m.Y', (string) $xml['Tarih']);

        if ($date === false) {
            Log::warning('exchange-rates:fetch: TCMB yanıtında tarih yok.');

            return null;
        }

        $saved = [];

        foreach (Currency::foreign() as $currency) {
            $node = $xml->xpath(sprintf('//Currency[@CurrencyCode="%s"]', $currency->value))[0] ?? null;
            $buying = trim((string) ($node->ForexBuying ?? ''));
            $selling = trim((string) ($node->ForexSelling ?? ''));

            if ($node === null || ! Money::isValid($buying, Money::RATE_SCALE) || ! Money::isValid($selling, Money::RATE_SCALE)) {
                Log::warning("exchange-rates:fetch: TCMB yanıtında {$currency->value} kuru yok.");

                continue;
            }

            $rate = DB::transaction(function () use ($currency, $date, $buying, $selling): ?ExchangeRate {
                if (ExchangeRate::query()->where('currency', $currency->value)->whereDate('rate_date', $date)->lockForUpdate()->exists()) {
                    return null;
                }

                $rate = new ExchangeRate;
                $rate->forceFill([
                    'currency' => $currency,
                    'rate_date' => $date,
                    'forex_buying' => Money::parse($buying, Money::RATE_SCALE),
                    'forex_selling' => Money::parse($selling, Money::RATE_SCALE),
                    'source' => ExchangeRateSource::Tcmb,
                ])->save();

                return $rate;
            });

            if ($rate !== null) {
                $saved[] = $rate;
            }
        }

        return $saved;
    }

    /**
     * Kuru elle girer veya düzeltir (aynı para birimi ve tarih varsa günceller).
     *
     * @param  int|null  $expectedLockVersion  Düzenlemede formun açıldığı andaki sürüm.
     */
    public function saveManual(Currency|string $currency, mixed $date, mixed $buying, mixed $selling, User $user, ?ExchangeRate $existing = null, ?int $expectedLockVersion = null): ExchangeRate
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);

        if ($currency === Currency::TRY) {
            throw new BusinessRuleException('TRY için kur girilmez.');
        }

        $date = CarbonImmutable::parse($date)->startOfDay();
        $buying = $this->rate($buying, 'Döviz alış');
        $selling = $this->rate($selling, 'Döviz satış');

        return DB::transaction(function () use ($currency, $date, $buying, $selling, $user, $existing, $expectedLockVersion): ExchangeRate {
            $rate = $existing ?? new ExchangeRate;

            $duplicate = ExchangeRate::query()
                ->where('currency', $currency->value)
                ->whereDate('rate_date', $date)
                ->when($rate->exists, fn ($query) => $query->whereKeyNot($rate->getKey()))
                ->exists();

            if ($duplicate) {
                throw new BusinessRuleException("{$currency->value} için {$date->format('d.m.Y')} tarihli kur zaten var; onu düzenleyin.");
            }

            $rate->forceFill([
                'currency' => $currency,
                'rate_date' => $date,
                'forex_buying' => $buying,
                'forex_selling' => $selling,
                'source' => ExchangeRateSource::Manual,
                'user_id' => $user->getKey(),
            ]);

            $rate->exists ? $rate->saveExpectingVersion($expectedLockVersion ?? $rate->lock_version) : $rate->save();

            return $rate;
        });
    }

    /**
     * Bir tarihin fatura kuru: o tarih ve öncesindeki en son kur (config accounting.invoice_rate_type). TRY için 1.
     *
     * @throws BusinessRuleException Kur yoksa.
     */
    public function rateFor(Currency $currency, CarbonInterface $date): string
    {
        if ($currency === Currency::TRY) {
            return '1.000000';
        }

        $rate = ExchangeRate::query()
            ->where('currency', $currency->value)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        if ($rate === null) {
            throw new BusinessRuleException("{$currency->value} için {$date->format('d.m.Y')} veya öncesine ait kur yok. Döviz kurları ekranından kuru girin.");
        }

        return $rate->invoiceRate();
    }

    private function rate(mixed $value, string $label): string
    {
        $rate = Money::parse($value ?? '', Money::RATE_SCALE);

        if (Money::compare($rate, '0') <= 0) {
            throw new BusinessRuleException("{$label} kuru sıfırdan büyük olmalıdır.");
        }

        return $rate;
    }
}
