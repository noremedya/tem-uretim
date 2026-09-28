<?php

namespace App\Filament\Resources\Parts\Pages;

use App\Enums\Permission;
use App\Filament\Pages\StockOperation;
use App\Filament\Resources\Parts\Actions\PartStatusActions;
use App\Filament\Resources\Parts\PartResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewPart extends ViewRecord
{
    protected static string $resource = PartResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stockOperation')
                ->label('Stok işlemi')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn (): bool => Auth::user()->can(Permission::StockMove))
                ->url(fn (): string => StockOperation::getUrl(['part' => $this->getRecord()->getKey()])),
            EditAction::make(),
            PartStatusActions::deactivate(),
            PartStatusActions::activate(),
        ];
    }
}
