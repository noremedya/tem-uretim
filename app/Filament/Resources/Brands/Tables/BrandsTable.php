<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Brand;
use App\Services\BrandService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Ad')->searchable()->sortable(),
                TextColumn::make('parts_count')->label('Ürün sayısı')->counts('parts')->numeric()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Durum')->trueLabel('Aktif')->falseLabel('Pasif')->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                static::status('deactivate', 'Pasifleştir', 'danger', fn (Brand $b) => app(BrandService::class)->deactivate($b), 'Marka pasifleştirildi'),
                static::status('activate', 'Aktifleştir', 'success', fn (Brand $b) => app(BrandService::class)->activate($b), 'Marka aktifleştirildi'),
            ]);
    }

    private static function status(string $name, string $label, string $color, \Closure $run, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize($name)
            ->requiresConfirmation()
            ->modalDescription($name === 'deactivate' ? 'Pasif marka yeni ürünlerde seçilemez; mevcut ürünlerde kalır.' : null)
            ->action(function (Brand $record, Action $action) use ($run, $done): void {
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
