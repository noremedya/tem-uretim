<?php

namespace App\Filament\Resources\Parts\Schemas;

use App\Models\Part;
use App\Support\Quantity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        // Tıklanınca görsel yeni sekmede tam boyutta açılır.
                        ImageEntry::make('image')
                            ->hiddenLabel()
                            ->state(fn (Part $record): ?string => $record->imageUrl())
                            ->visible(fn (Part $record): bool => $record->image_path !== null)
                            ->imageHeight(180)
                            ->url(fn (Part $record): ?string => $record->imageUrl(), shouldOpenInNewTab: true)
                            ->columnSpanFull(),
                        TextEntry::make('code')->label('Kod'),
                        TextEntry::make('name')->label('Ad'),
                        TextEntry::make('type')->label('Tür')->badge(),
                        TextEntry::make('unit.name')->label('Birim'),
                        TextEntry::make('stockBalance.quantity')
                            ->label('Bakiye')
                            ->formatStateUsing(fn ($state, Part $record): string => Quantity::format($state, $record->unit->name))
                            ->badge()
                            ->color(fn (Part $record): string => $record->isCriticalAt((string) $record->stockBalance?->quantity) ? 'danger' : 'success'),
                        TextEntry::make('critical_level')
                            ->label('Kritik seviye')
                            ->formatStateUsing(fn ($state, Part $record): string => $record->tracksCriticalLevel()
                                ? Quantity::format($state, $record->unit->name)
                                : 'Takip yok'),
                        TextEntry::make('barcode')->label('Barkod')->placeholder('—'),
                        TextEntry::make('brand.name')->label('Marka')->placeholder('—'),
                        IconEntry::make('is_active')->label('Aktif')->boolean(),
                        TextEntry::make('description')->label('Açıklama')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
