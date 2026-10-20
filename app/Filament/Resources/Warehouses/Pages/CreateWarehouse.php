<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Services\WarehouseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWarehouse extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = WarehouseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(WarehouseService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
