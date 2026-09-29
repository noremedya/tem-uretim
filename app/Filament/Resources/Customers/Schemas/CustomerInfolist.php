<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')->label('Ad / ünvan')->columnSpan(2),
                        IconEntry::make('is_active')->label('Aktif')->boolean(),
                        TextEntry::make('tax_number')->label('Vergi no / TCKN')->placeholder('—'),
                        TextEntry::make('tax_office')->label('Vergi dairesi')->placeholder('—'),
                        TextEntry::make('phone')->label('Telefon')->placeholder('—'),
                        TextEntry::make('email')->label('E-posta')->placeholder('—'),
                        TextEntry::make('address')->label('Adres')->placeholder('—')->columnSpan(2),
                    ]),
            ]);
    }
}
