<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\Actions\WorkOrderStatusActions;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\AuditTrailAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            WorkOrderStatusActions::start(),
            WorkOrderStatusActions::complete(),
            WorkOrderStatusActions::reverse(),
            WorkOrderStatusActions::revertToPlanned(),
            WorkOrderStatusActions::refreshMaterials(),
            EditAction::make(),
            WorkOrderStatusActions::cancel(),
        ];
    }
}
