<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Models\WorkOrder;
use App\Models\WorkOrderProduction;
use App\Support\Quantity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Üretim kayıtları (kısım kısım üretim; salt okunur). Motorlu kayıtta seri numaraları gösterilir; geri alınan kayıt
 * kim, ne zaman ve neden bilgisiyle kalır.
 */
class ProductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'productions';

    protected static ?string $title = 'Üretim kayıtları';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'reversedBy', 'motorUnits' => fn ($q) => $q->orderBy('id')]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Henüz üretim kaydı yok')
            ->columns([
                TextColumn::make('created_at')->label('Tarih')->dateTime('d.m.Y H:i'),
                TextColumn::make('quantity')->label('Miktar')->alignEnd()
                    ->formatStateUsing(fn ($state): string => Quantity::format($state, $this->unitName())),
                TextColumn::make('user.name')->label('Kaydeden'),
                TextColumn::make('serials')->label('Seri numaraları')
                    ->state(fn (WorkOrderProduction $record): ?string => $record->motorUnits->isEmpty() ? null : $this->serialRange($record))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('reversed_at')->label('Geri alındı')
                    ->state(fn (WorkOrderProduction $record): ?string => $record->isReversed()
                        ? "{$record->reversed_at->format('d.m.Y H:i')} · {$record->reversedBy->name}: {$record->reversal_reason}"
                        : null)
                    ->placeholder('—')
                    ->color('danger')
                    ->wrap(),
            ]);
    }

    /** Ardışık seri numaralarında ilk – son; azsa hepsi. */
    private function serialRange(WorkOrderProduction $record): string
    {
        $serials = $record->motorUnits->pluck('serial_number');

        return $serials->count() <= 3 ? $serials->implode(', ') : "{$serials->first()} … {$serials->last()} ({$serials->count()} motor)";
    }

    private function unitName(): string
    {
        /** @var WorkOrder $owner */
        $owner = $this->getOwnerRecord();

        return $owner->loadMissing('product.unit')->product->unit->name;
    }
}
