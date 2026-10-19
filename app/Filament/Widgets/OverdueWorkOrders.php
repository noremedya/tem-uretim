<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/** Geciken iş emirleri (yönetici, depo): planlanan bitişi geçmiş açık iş emirleri. */
class OverdueWorkOrders extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Geciken iş emirleri';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // Yalnızca operatör rolündeki kullanıcının ana ekranı sade kalır (CLAUDE.md bölüm 6).
        $user = Auth::user();

        return $user !== null && $user->can(Permission::WorkOrdersViewAll) && ! $user->hasExactRoles([Role::Operator->value]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WorkOrder::query()->with(['product', 'assignedUser'])->overdue()->orderBy('planned_end'))
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('work_order_number')->label('İş emri')->weight('bold'),
                TextColumn::make('product.name')->label('Üretilecek'),
                TextColumn::make('status')->label('Durum')->badge(),
                TextColumn::make('assignedUser.name')->label('Operatör')->placeholder('Atanmadı'),
                TextColumn::make('planned_end')->label('Planlanan bitiş')->date('d.m.Y')->color('danger'),
            ])
            ->emptyStateHeading('Geciken iş emri yok');
    }
}
