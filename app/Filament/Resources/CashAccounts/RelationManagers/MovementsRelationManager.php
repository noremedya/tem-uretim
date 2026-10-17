<?php

namespace App\Filament\Resources\CashAccounts\RelationManagers;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Kasa/banka hareketleri (salt okunur, değiştirilemez). */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Hareketler';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'cashAccount']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('transaction_date')->label('Tarih')->date('d.m.Y')->sortable(),
                TextColumn::make('type')->label('Tür')->badge(),
                TextColumn::make('description')->label('Açıklama')->wrap()->placeholder('—'),
                TextColumn::make('in')->label('Giriş')->alignEnd()
                    ->state(fn (CashMovement $record): ?string => Money::compare((string) $record->amount, '0') > 0 ? Money::format((string) $record->amount) : null),
                TextColumn::make('out')->label('Çıkış')->alignEnd()
                    ->state(fn (CashMovement $record): ?string => Money::compare((string) $record->amount, '0') < 0 ? Money::format(ltrim((string) $record->amount, '-')) : null),
                TextColumn::make('user.name')->label('Kullanıcı')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tür')->options(CashMovementType::class),
            ]);
    }
}
