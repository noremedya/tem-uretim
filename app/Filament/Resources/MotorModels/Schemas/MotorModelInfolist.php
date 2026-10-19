<?php

namespace App\Filament\Resources\MotorModels\Schemas;

use App\Filament\Resources\Parts\PartResource;
use App\Models\MotorModel;
use App\Support\Gtip;
use App\Support\Quantity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class MotorModelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')->label('Kod'),
                        TextEntry::make('name')->label('Ad'),
                        IconEntry::make('is_active')->label('Aktif')->boolean(),
                        // Mamul stoğu = stoktaki motor sayısı (yalnızca üretim ve sevkle değişir).
                        TextEntry::make('finishedPart.stockBalance.quantity')
                            ->label('Mamul stoğu')
                            ->formatStateUsing(fn ($state): string => Quantity::format($state, 'Adet'))
                            ->url(fn (MotorModel $record): ?string => auth()->user()?->can('view', $record->finishedPart)
                                ? PartResource::getUrl('view', ['record' => $record->finished_part_id])
                                : null),
                        TextEntry::make('description')->label('Açıklama')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Teknik özellikler')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('power_kw')->label('Güç')
                            ->formatStateUsing(fn ($state): string => Quantity::format($state, 'kW'))
                            ->placeholder('—'),
                        TextEntry::make('speed_rpm')->label('Devir')
                            ->formatStateUsing(fn ($state): string => Number::format($state).' d/dk')
                            ->placeholder('—'),
                        TextEntry::make('voltage')->label('Gerilim')->placeholder('—'),
                        TextEntry::make('frequency_hz')->label('Frekans')
                            ->formatStateUsing(fn ($state): string => "{$state} Hz")
                            ->placeholder('—'),
                        TextEntry::make('pole_count')->label('Kutup sayısı')->placeholder('—'),
                        TextEntry::make('phase')->label('Faz')->placeholder('—'),
                        TextEntry::make('frame_size')->label('Gövde ölçüsü')->placeholder('—'),
                        TextEntry::make('mounting_type')->label('Montaj şekli')->placeholder('—'),
                        TextEntry::make('protection_class')->label('Koruma sınıfı')->placeholder('—'),
                        TextEntry::make('efficiency_class')->label('Verim sınıfı')->placeholder('—'),
                        TextEntry::make('gtip_code')->label('GTİP')->placeholder('—')->formatStateUsing(fn (?string $state): ?string => Gtip::format($state)),
                        TextEntry::make('net_weight_kg')->label('Net ağırlık')->placeholder('—')->formatStateUsing(fn ($state): string => Quantity::format((string) $state, 'kg')),
                    ]),
            ]);
    }
}
