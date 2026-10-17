<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use App\Support\Quantity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/** Operatör ana ekranı: kendisine atanmış açık iş emirleri (CLAUDE.md bölüm 6). */
class AssignedWorkOrders extends TableWidget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Bana atanan iş emirleri';

    public static function canView(): bool
    {
        return Auth::user()?->hasRole(Role::Operator) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WorkOrder::query()
                ->with('product.unit')
                ->where('assigned_user_id', Auth::id())
                ->open()
                ->orderBy('planned_end'))
            ->paginated(false)
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('work_order_number')->label('İş emri')->weight('bold'),
                TextColumn::make('product.name')->label('Üretilecek')
                    ->description(fn (WorkOrder $record): string => $record->product->code),
                TextColumn::make('quantity')->label('Adet')
                    ->formatStateUsing(fn ($state, WorkOrder $record): string => Quantity::format($state, $record->product->unit->name)),
                TextColumn::make('status')->label('Durum')->badge(),
                TextColumn::make('planned_end')->label('Planlanan bitiş')->date('d.m.Y')
                    ->color(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'danger' : null),
            ])
            ->emptyStateHeading('Size atanmış açık iş emri yok');
    }
}
