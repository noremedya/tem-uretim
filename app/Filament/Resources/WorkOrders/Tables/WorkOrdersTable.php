<?php

namespace App\Filament\Resources\WorkOrders\Tables;

use App\Enums\PartType;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Quantity;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.unit', 'assignedUser', 'orderItem.order', 'productionBatch']))
            ->defaultSort('planned_end')
            ->columns([
                TextColumn::make('work_order_number')->label('İş emri no')->searchable()->sortable()->weight('bold'),
                TextColumn::make('product.code')
                    ->label('Üretilecek')
                    ->description(fn (WorkOrder $record): string => $record->product->name)
                    ->searchable(['code', 'name']),
                TextColumn::make('quantity')
                    ->label('Adet')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, WorkOrder $record): string => Quantity::format($state, $record->product->unit->name)),
                TextColumn::make('status')->label('Durum')->badge(),
                TextColumn::make('assignedUser.name')->label('Operatör')->placeholder('Atanmadı'),
                TextColumn::make('planned_end')
                    ->label('Planlanan bitiş')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->icon(fn (WorkOrder $record) => $record->isOverdue() ? 'heroicon-m-exclamation-triangle' : null),
                TextColumn::make('orderItem.order.order_number')->label('Sipariş')->placeholder('—')->toggleable(),
                TextColumn::make('productionBatch.batch_number')->label('Parti')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(WorkOrderStatus::class)
                    ->multiple()
                    ->default([WorkOrderStatus::Planned->value, WorkOrderStatus::InProgress->value]),
                SelectFilter::make('product_type')
                    ->label('Tür')
                    ->options([
                        PartType::FinishedGood->value => 'Motor (mamul)',
                        PartType::SemiFinished->value => 'Yarı mamul',
                    ])
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('product', fn (Builder $product) => $product->where('type', $data['value']))
                        : $query),
                SelectFilter::make('assigned_user_id')
                    ->label('Operatör')
                    ->options(fn (): array => User::query()->role(Role::Operator->value)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('production_batch_id')
                    ->label('Parti')
                    ->relationship('productionBatch', 'batch_number'),
                Filter::make('overdue')
                    ->label('Yalnızca gecikenler')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->overdue()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
