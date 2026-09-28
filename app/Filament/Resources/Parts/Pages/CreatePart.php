<?php

namespace App\Filament\Resources\Parts\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Parts\PartResource;
use App\Services\PartService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePart extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = PartResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(PartService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
