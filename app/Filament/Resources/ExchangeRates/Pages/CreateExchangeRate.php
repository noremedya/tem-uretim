<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Services\ExchangeRateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateExchangeRate extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = ExchangeRateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(ExchangeRateService::class)->saveManual(
            $data['currency'], $data['rate_date'], $data['forex_buying'], $data['forex_selling'], Auth::user(),
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
