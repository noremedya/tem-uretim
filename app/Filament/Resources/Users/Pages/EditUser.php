<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\Users\Actions\UserStatusActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Aksiyon kaydın sürümünü artırır; form güncel veriyle yeniden doldurulur.
            UserStatusActions::deactivate()->after(fn () => $this->fillForm()),
            UserStatusActions::activate()->after(fn () => $this->fillForm()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['roles'] = $user->getRoleNames()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(UserService::class)->update($record, $data, $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }
}
