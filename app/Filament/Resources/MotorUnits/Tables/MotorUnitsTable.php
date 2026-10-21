<?php

namespace App\Filament\Resources\MotorUnits\Tables;

use App\Enums\MotorUnitStatus;
use App\Filament\Resources\MotorUnits\Actions\ShipMotorUnitsAction;
use App\Filament\Resources\MotorUnits\Actions\TransferMotorUnitsAction;
use App\Filament\Support\LabelActions;
use App\Models\MotorUnit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MotorUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['motorModel', 'workOrder', 'order', 'productionBatch', 'warehouse']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('serial_number')->label('Seri no')->searchable()->sortable()->weight('bold')->copyable(),
                TextColumn::make('motorModel.code')
                    ->label('Model')
                    ->description(fn (MotorUnit $record): string => $record->motorModel->name)
                    ->searchable(['code', 'name']),
                TextColumn::make('status')->label('Durum')->badge(),
                TextColumn::make('warehouse.name')->label('Depo')->toggleable(),
                TextColumn::make('production_date')->label('Üretim tarihi')->date('d.m.Y')->sortable(),
                TextColumn::make('workOrder.work_order_number')->label('İş emri')->searchable(),
                TextColumn::make('order.order_number')->label('Sipariş')->placeholder('—')->searchable(),
                TextColumn::make('productionBatch.batch_number')->label('Parti')->placeholder('—')->toggleable(),
                TextColumn::make('shipped_at')->label('Sevk')->dateTime('d.m.Y H:i')->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(MotorUnitStatus::class)->default(MotorUnitStatus::InStock->value),
                SelectFilter::make('motor_model_id')->label('Model')->relationship('motorModel', 'code')->searchable()->preload(),
                SelectFilter::make('warehouse_id')->label('Depo')->relationship('warehouse', 'name'),
                SelectFilter::make('production_batch_id')->label('Parti')->relationship('productionBatch', 'batch_number'),
            ])
            ->recordActions([
                ViewAction::make(),
                ShipMotorUnitsAction::single(),
                TransferMotorUnitsAction::single(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ShipMotorUnitsAction::bulk(),
                    TransferMotorUnitsAction::bulk(),
                    LabelActions::motorsBulk(),
                ]),
            ]);
    }
}
