<?php

namespace App\Filament\Resources\MotorModels\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Services\MotorModelService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMotorModel extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = MotorModelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(MotorModelService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
