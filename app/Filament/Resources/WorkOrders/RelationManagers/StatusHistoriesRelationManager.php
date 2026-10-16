<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * İş emri durum geçmişi (salt okunur, değiştirilemez).
 */
class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Durum geçmişi';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Tarih')->dateTime('d.m.Y H:i'),
                TextColumn::make('from_status')->label('Önceki')->badge()->placeholder('—'),
                TextColumn::make('to_status')->label('Yeni')->badge(),
                TextColumn::make('user.name')->label('Kullanıcı'),
                TextColumn::make('description')->label('Açıklama')->placeholder('—')->wrap(),
            ]);
    }
}
