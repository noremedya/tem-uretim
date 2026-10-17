<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AuditTrailAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            EditAction::make(),
            CancelOrderAction::make(),
        ];
    }
}
