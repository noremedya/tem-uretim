<?php

namespace App\Filament\Resources\PartSubtypes\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\PartSubtypes\PartSubtypeResource;
use App\Services\PartSubtypeService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPartSubtype extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = PartSubtypeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(PartSubtypeService::class)->update($record, $data, $this->lockVersion);
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
