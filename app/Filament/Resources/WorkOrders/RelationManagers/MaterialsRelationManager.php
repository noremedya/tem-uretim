<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Models\WorkOrderMaterial;
use App\Support\Quantity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * İş emrinin malzeme listesi ve yetersiz malzeme uyarısı (salt okunur). Eksik = fire dahil ihtiyaç − mevcut stok;
 * başka iş emirlerinin ihtiyacı hesaba katılmaz.
 */
class MaterialsRelationManager extends RelationManager
{
    protected static string $relationship = 'materials';

    protected static ?string $title = 'Malzemeler';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['part.unit', 'part.stockBalance']))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('part.code')->label('Kod'),
                TextColumn::make('part.name')->label('Malzeme'),
                TextColumn::make('part.type')->label('Tür')->badge(),
                TextColumn::make('required_quantity')
                    ->label('Net ihtiyaç')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)),
                TextColumn::make('planned_scrap')
                    ->label('Standart fire')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)
                        .' (%'.Quantity::format((string) $record->scrap_rate).')'),
                TextColumn::make('actual_scrap')
                    ->label('Gerçekleşen fire')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, WorkOrderMaterial $record): string => Quantity::format($state, $record->part->unit->name)),
                TextColumn::make('available')
                    ->label('Stok')
                    ->alignEnd()
                    ->state(fn (WorkOrderMaterial $record): string => Quantity::format((string) ($record->part->stockBalance?->quantity ?? 0), $record->part->unit->name)),
                TextColumn::make('missing')
                    ->label('Eksik')
                    ->alignEnd()
                    ->state(function (WorkOrderMaterial $record): string {
                        $missing = Quantity::sub($record->totalRequirement(), (string) ($record->part->stockBalance?->quantity ?? 0));

                        return Quantity::compare($missing, '0') > 0 ? Quantity::format($missing, $record->part->unit->name) : '—';
                    })
                    ->color(fn (string $state): ?string => $state !== '—' ? 'danger' : null)
                    ->weight(fn (string $state): ?string => $state !== '—' ? 'bold' : null),
            ]);
    }
}
