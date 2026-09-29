<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\CustomerService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(CustomerService::class)->create($data, (bool) ($data['confirm_duplicate_name'] ?? false)));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
