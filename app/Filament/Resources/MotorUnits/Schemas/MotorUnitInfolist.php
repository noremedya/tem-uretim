<?php

namespace App\Filament\Resources\MotorUnits\Schemas;

use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\MotorUnit;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class MotorUnitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('serial_number')->label('Seri no')->weight('bold')->copyable(),
                    TextEntry::make('status')->label('Durum')->badge(),
                    TextEntry::make('warehouse.name')->label('Depo'),
                    TextEntry::make('motorModel.code')
                        ->label('Model')
                        ->formatStateUsing(fn (MotorUnit $record): string => "{$record->motorModel->code} — {$record->motorModel->name}")
                        ->url(fn (MotorUnit $record): string => MotorModelResource::getUrl('view', ['record' => $record->motor_model_id])),
                    TextEntry::make('production_date')->label('Üretim tarihi')->date('d.m.Y'),
                    TextEntry::make('workOrder.work_order_number')
                        ->label('İş emri')
                        ->url(fn (MotorUnit $record): ?string => Auth::user()->can('view', $record->workOrder)
                            ? WorkOrderResource::getUrl('view', ['record' => $record->work_order_id])
                            : null),
                    TextEntry::make('order.order_number')
                        ->label('Sipariş')
                        ->placeholder('—')
                        ->url(fn (MotorUnit $record): ?string => $record->order && Auth::user()->can('view', $record->order)
                            ? OrderResource::getUrl('view', ['record' => $record->order_id])
                            : null),
                    TextEntry::make('productionBatch.batch_number')->label('Parti')->placeholder('—'),
                    TextEntry::make('shipped_at')->label('Sevk tarihi')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('shippedBy.name')->label('Sevk eden')->placeholder('—'),
                    TextEntry::make('notes')->label('Not')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
