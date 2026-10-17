<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Services\CashAccountService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCashAccount extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = CashAccountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(CashAccountService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
