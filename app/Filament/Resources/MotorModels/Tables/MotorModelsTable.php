<?php

namespace App\Filament\Resources\MotorModels\Tables;

use App\Enums\MotorPhase;
use App\Filament\Resources\MotorModels\Actions\MotorModelStatusActions;
use App\Support\Quantity;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class MotorModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kod')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('power_kw')
                    ->label('Güç')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Quantity::format($state, 'kW'))
                    ->placeholder('—'),
                TextColumn::make('speed_rpm')
                    ->label('Devir')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Number::format($state).' d/dk')
                    ->placeholder('—'),
                TextColumn::make('voltage')
                    ->label('Gerilim')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('phase')
                    ->label('Faz')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('frame_size')
                    ->label('Gövde')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('efficiency_class')
                    ->label('Verim sınıfı')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Durum')
                    ->trueLabel('Aktif')
                    ->falseLabel('Pasif')
                    ->placeholder('Tümü')
                    ->default(true),
                SelectFilter::make('phase')
                    ->label('Faz')
                    ->options(MotorPhase::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                MotorModelStatusActions::deactivate(),
                MotorModelStatusActions::activate(),
            ]);
    }
}
