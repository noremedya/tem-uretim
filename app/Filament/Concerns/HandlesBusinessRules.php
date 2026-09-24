<?php

namespace App\Filament\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use Closure;

/**
 * Filament sayfalarında servis çağrılarını sarar: iş kuralı ihlalinde bildirim gösterir,
 * işlemi durdurur ve veritabanı transaction'ını geri alır.
 */
trait HandlesBusinessRules
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withBusinessRules(Closure $callback, ?string $refreshUrl = null): mixed
    {
        try {
            return $callback();
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception, $refreshUrl);

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }
}
