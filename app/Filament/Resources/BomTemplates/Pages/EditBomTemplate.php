<?php

namespace App\Filament\Resources\BomTemplates\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\BomTemplates\BomTemplateResource;
use App\Services\BomTemplateService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBomTemplate extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = BomTemplateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'product_type' => $this->getRecord()->product_type->value, 'lines' => BomTemplateResource::linesForForm($this->getRecord())];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(BomTemplateService::class)->update($record, $data, $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
