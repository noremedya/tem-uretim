<?php

namespace App\Filament\Resources\BomTemplates\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\BomTemplates\BomTemplateResource;
use App\Services\BomTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBomTemplate extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = BomTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(BomTemplateService::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
