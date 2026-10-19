<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Parts\PartResource;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Support\Quantity;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class WorkOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('İptal edildi')
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->columnSpanFull()
                ->visible(fn (WorkOrder $record): bool => $record->status === WorkOrderStatus::Cancelled)
                ->schema([
                    TextEntry::make('cancellation_reason')->label('İptal açıklaması'),
                ]),
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    ImageEntry::make('product_image')
                        ->hiddenLabel()
                        ->state(fn (WorkOrder $record): ?string => $record->product->imageUrl())
                        ->visible(fn (WorkOrder $record): bool => $record->product->image_path !== null)
                        ->imageHeight(140)
                        ->columnSpanFull(),
                    TextEntry::make('work_order_number')->label('İş emri no')->weight('bold')->copyable(),
                    TextEntry::make('status')->label('Durum')->badge(),
                    TextEntry::make('product.code')
                        ->label('Üretilecek')
                        ->formatStateUsing(fn (WorkOrder $record): string => "{$record->product->code} — {$record->product->name}")
                        ->url(fn (WorkOrder $record): ?string => Auth::user()->can('view', $record->product)
                            ? PartResource::getUrl('view', ['record' => $record->product_part_id])
                            : null),
                    TextEntry::make('quantity')
                        ->label('Adet')
                        ->formatStateUsing(fn ($state, WorkOrder $record): string => Quantity::format($state, $record->product->unit->name)),
                    TextEntry::make('orderItem.order.order_number')
                        ->label('Sipariş')
                        ->placeholder('Siparişe bağlı değil')
                        ->url(fn (WorkOrder $record): ?string => $record->orderItem && Auth::user()->can('view', $record->orderItem->order)
                            ? OrderResource::getUrl('view', ['record' => $record->orderItem->order_id])
                            : null),
                    TextEntry::make('assignedUser.name')->label('Atanan operatör')->placeholder('Atanmadı'),
                    TextEntry::make('productionBatch.batch_number')->label('Parti')->placeholder('—'),
                    TextEntry::make('material_status')
                        ->label('Malzeme durumu')
                        ->state(fn (WorkOrder $record): string => static::shortageSummary($record))
                        ->color(fn (WorkOrder $record): string => static::shortageCount($record) > 0 ? 'danger' : 'success')
                        ->visible(fn (WorkOrder $record): bool => $record->status->isOpen()),
                    TextEntry::make('planned_start')->label('Planlanan başlangıç')->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('planned_end')
                        ->label('Planlanan bitiş')
                        ->date('d.m.Y')
                        ->color(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'danger' : null)
                        ->helperText(fn (WorkOrder $record): ?string => $record->isOverdue() ? 'Gecikti' : null),
                    TextEntry::make('started_at')->label('Başlama')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('completed_at')->label('Bitiş')->dateTime('d.m.Y H:i')->placeholder('—'),
                    TextEntry::make('notes')->label('Notlar')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    private static function shortageCount(WorkOrder $record): int
    {
        return collect(app(WorkOrderService::class)->materialStatus($record))
            ->filter(fn (array $row) => Quantity::compare($row['missing'], '0') > 0)
            ->count();
    }

    private static function shortageSummary(WorkOrder $record): string
    {
        $count = static::shortageCount($record);

        return $count === 0 ? 'Tüm malzemeler stokta yeterli' : "{$count} malzemede eksik var (Malzemeler sekmesine bakın)";
    }
}
