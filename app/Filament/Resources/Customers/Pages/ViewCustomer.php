<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\Actions\CustomerStatusActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newOrder')
                ->label('Yeni sipariş')
                ->icon(Heroicon::OutlinedPlus)
                // Pasif müşteriye sipariş açılamaz.
                ->visible(fn (Customer $record): bool => $record->is_active && Auth::user()->can('create', Order::class))
                ->url(fn (Customer $record): string => OrderResource::getUrl('create', ['customer' => $record->getKey()])),
            EditAction::make(),
            CustomerStatusActions::deactivate(),
            CustomerStatusActions::activate(),
        ];
    }
}
