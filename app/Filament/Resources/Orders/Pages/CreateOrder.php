<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\OrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrder extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(OrderService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
