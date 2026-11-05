<?php

namespace App\Filament\Resources\MotorDesigns\Pages;

use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Filament\Support\AuditTrailAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMotorDesign extends ViewRecord
{
    protected static string $resource = MotorDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), AuditTrailAction::make()];
    }
}
