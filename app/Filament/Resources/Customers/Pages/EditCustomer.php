<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\Customers\Actions\CustomerStatusActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\CustomerService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // Aksiyon kaydın sürümünü artırır; form güncel veriyle yeniden doldurulur.
            CustomerStatusActions::deactivate()->after(fn () => $this->fillForm()),
            CustomerStatusActions::activate()->after(fn () => $this->fillForm()),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(CustomerService::class)->update($record, $data, $this->lockVersion, (bool) ($data['confirm_duplicate_name'] ?? false));
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }
}
