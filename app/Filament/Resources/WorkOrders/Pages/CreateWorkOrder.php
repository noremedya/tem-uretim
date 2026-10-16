<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateWorkOrder extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = WorkOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(WorkOrderService::class)->create($data, Auth::user()));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
