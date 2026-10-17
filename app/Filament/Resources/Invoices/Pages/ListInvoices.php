<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceDirection;
use App\Enums\Permission;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Yeni belge'),
        ];
    }

    public function getTabs(): array
    {
        if (! Auth::user()?->can(Permission::InvoicesManage)) {
            return [];
        }

        return [
            'all' => Tab::make('Tümü'),
            'sales' => Tab::make('Satış')->modifyQueryUsing(fn (Builder $query) => $query->where('direction', InvoiceDirection::Sales->value)),
            'purchase' => Tab::make('Alış')->modifyQueryUsing(fn (Builder $query) => $query->where('direction', InvoiceDirection::Purchase->value)),
        ];
    }
}
