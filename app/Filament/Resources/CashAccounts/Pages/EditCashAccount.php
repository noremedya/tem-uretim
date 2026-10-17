<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Services\CashAccountService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditCashAccount extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = CashAccountResource::class;

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(CashAccountService::class)->update($record, Arr::except($data, ['kind', 'currency']), $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }
}
