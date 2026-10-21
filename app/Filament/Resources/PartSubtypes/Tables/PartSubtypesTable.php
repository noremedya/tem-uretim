<?php

namespace App\Filament\Resources\PartSubtypes\Tables;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\PartSubtype;
use App\Services\PartSubtypeService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartSubtypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("array_position(ARRAY['raw_material','component','direct_consumable','indirect_consumable','semi_finished','finished_good']::text[], type::text)")->orderBy('name'))
            ->columns([
                TextColumn::make('type')->label('Ana tür')->badge(),
                TextColumn::make('name')->label('Ad')->searchable()->weight('bold'),
                TextColumn::make('parts_count')->label('Ürün sayısı')->counts('parts')->numeric(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Ana tür')->options(PartType::class),
                TernaryFilter::make('is_active')->label('Durum')->trueLabel('Aktif')->falseLabel('Pasif')->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                static::status('deactivate', 'Pasifleştir', 'danger', fn (PartSubtype $s) => app(PartSubtypeService::class)->deactivate($s), 'Alt tür pasifleştirildi'),
                static::status('activate', 'Aktifleştir', 'success', fn (PartSubtype $s) => app(PartSubtypeService::class)->activate($s), 'Alt tür aktifleştirildi'),
            ]);
    }

    private static function status(string $name, string $label, string $color, \Closure $run, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize($name)
            ->requiresConfirmation()
            ->modalDescription($name === 'deactivate' ? 'Pasif alt tür yeni ürünlerde seçilemez; mevcut ürünlerde kalır.' : null)
            ->action(function (PartSubtype $record, Action $action) use ($run, $done): void {
                try {
                    $run($record);
                } catch (BusinessRuleException $e) {
                    BusinessRuleNotification::send($e);
                    $action->halt();
                }
                Notification::make()->success()->title($done)->send();
            });
    }
}
