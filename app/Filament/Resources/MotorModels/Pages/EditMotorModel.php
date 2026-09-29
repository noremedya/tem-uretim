<?php

namespace App\Filament\Resources\MotorModels\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\MotorModels\Actions\MotorModelStatusActions;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Services\MotorModelService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMotorModel extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = MotorModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Aksiyon kaydın sürümünü artırır; form güncel veriyle yeniden doldurulur.
            MotorModelStatusActions::deactivate()->after(fn () => $this->fillForm()),
            MotorModelStatusActions::activate()->after(fn () => $this->fillForm()),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(MotorModelService::class)->update($record, $data, $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }
}
