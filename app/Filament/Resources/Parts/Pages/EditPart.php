<?php

namespace App\Filament\Resources\Parts\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\Parts\Actions\PartStatusActions;
use App\Filament\Resources\Parts\PartResource;
use App\Services\PartService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPart extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = PartResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Aksiyon kaydın sürümünü artırır; form güncel veriyle yeniden doldurulur.
            PartStatusActions::deactivate()->after(fn () => $this->fillForm()),
            PartStatusActions::activate()->after(fn () => $this->fillForm()),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(PartService::class)->update($record, $data, $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }
}
