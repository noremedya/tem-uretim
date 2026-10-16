<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\Actions\WorkOrderStatusActions;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WorkOrderStatusActions::start(),
            WorkOrderStatusActions::revertToPlanned(),
            WorkOrderStatusActions::refreshMaterials(),
            EditAction::make(),
            WorkOrderStatusActions::cancel(),
        ];
    }
}
