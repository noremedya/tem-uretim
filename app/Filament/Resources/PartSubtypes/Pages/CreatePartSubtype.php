<?php

namespace App\Filament\Resources\PartSubtypes\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\PartSubtypes\PartSubtypeResource;
use App\Services\PartSubtypeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePartSubtype extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = PartSubtypeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(PartSubtypeService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
