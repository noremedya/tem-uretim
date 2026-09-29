<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOrder extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Order $order */
        $order = $this->getRecord();

        $data['items'] = $order->items()->orderBy('id')->get()
            ->map(fn (OrderItem $item): array => ['motor_model_id' => $item->motor_model_id, 'quantity' => $item->quantity])
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(OrderService::class)->update($record, $data, $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
