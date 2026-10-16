<?php

namespace App\Filament\Resources\ProductionBatches\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Services\ProductionBatchService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductionBatch extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = ProductionBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(ProductionBatchService::class)->create($data));
    }
}
