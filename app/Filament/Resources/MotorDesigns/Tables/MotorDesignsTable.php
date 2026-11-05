<?php

namespace App\Filament\Resources\MotorDesigns\Tables;

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorDesign;
use App\Services\MotorDesignService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MotorDesignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('Kod')->searchable()->sortable(),
                TextColumn::make('name')->label('Ad')->searchable()->sortable(),
                TextColumn::make('items_count')->label('Bileşen')->counts('items')->numeric()->alignEnd(),
                TextColumn::make('description')->label('Açıklama')->limit(60)->toggleable(),
                TextColumn::make('updated_at')->label('Son değişiklik')->dateTime('d.m.Y H:i')->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Durum')->trueLabel('Aktif')->falseLabel('Pasif')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::status('deactivate', 'Pasifleştir', 'danger', fn (MotorDesign $d) => app(MotorDesignService::class)->deactivate($d), 'Tasarım pasifleştirildi'),
                static::status('activate', 'Aktifleştir', 'success', fn (MotorDesign $d) => app(MotorDesignService::class)->activate($d), 'Tasarım aktifleştirildi'),
            ]);
    }

    private static function status(string $name, string $label, string $color, Closure $run, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize($name)
            ->requiresConfirmation()
            ->action(function (MotorDesign $record, Action $action) use ($run, $done): void {
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
