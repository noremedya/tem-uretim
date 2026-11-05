<?php

namespace App\Filament\Resources\MotorDesigns\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Services\MotorDesignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMotorDesign extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = MotorDesignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(MotorDesignService::class)->create($data));
    }

    // Bileşenler düzenleme sayfasında eklenir.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
