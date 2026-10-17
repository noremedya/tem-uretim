<?php

namespace App\Filament\Resources\Prices\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Prices\PriceResource;
use App\Services\PriceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePrice extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = PriceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(PriceService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
