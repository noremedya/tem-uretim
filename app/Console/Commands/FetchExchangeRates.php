<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * TCMB'den günlük EUR/USD kurlarını çeker. İnternet yoksa uyarı verir ama başarısız sayılmaz
 * (uygulama internetsiz çalışır; kur elle girilebilir).
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch';

    protected $description = 'TCMB günlük döviz kurlarını çeker (internet yoksa uyarı verir)';

    public function handle(ExchangeRateService $rates): int
    {
        $saved = $rates->fetchFromTcmb();

        if ($saved === null) {
            $this->warn('TCMB kurlarına ulaşılamadı; internet bağlantısını kontrol edin veya kuru elle girin.');

            return self::SUCCESS;
        }

        if ($saved === []) {
            $this->info('Bu tarihin kurları zaten kayıtlı.');

            return self::SUCCESS;
        }

        foreach ($saved as $rate) {
            $this->info(sprintf(
                '%s %s: alış %s, satış %s',
                $rate->rate_date->format('d.m.Y'),
                $rate->currency->value,
                Money::format((string) $rate->forex_buying, scale: Money::RATE_SCALE),
                Money::format((string) $rate->forex_selling, scale: Money::RATE_SCALE),
            ));
        }

        return self::SUCCESS;
    }
}
