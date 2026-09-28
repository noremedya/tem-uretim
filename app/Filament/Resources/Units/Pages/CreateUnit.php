<?php

namespace App\Filament\Resources\Units\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Units\UnitResource;
use App\Services\UnitService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUnit extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = UnitResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(UnitService::class)->create($data));
    }
}
