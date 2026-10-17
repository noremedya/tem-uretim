<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Services\ExchangeRateService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditExchangeRate extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = ExchangeRateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(ExchangeRateService::class)->saveManual(
                    $data['currency'], $data['rate_date'], $data['forex_buying'], $data['forex_selling'], Auth::user(),
                    existing: $record,
                    expectedLockVersion: $this->lockVersion,
                );
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
