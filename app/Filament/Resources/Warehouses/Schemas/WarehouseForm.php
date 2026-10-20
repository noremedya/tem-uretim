<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Enums\PartType;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('code')->label('Kod')->required()->maxLength(20)->helperText('Kısa kod, ör. HAM, URT, YM, MML.'),
                    TextInput::make('name')->label('Ad')->required()->maxLength(100),
                    CheckboxList::make('default_part_types')
                        ->label('Varsayılan depo olduğu ürün türleri')
                        ->options(collect(PartType::cases())->mapWithKeys(fn (PartType $type) => [$type->value => $type->getLabel()])->all())
                        ->columns(2)
                        ->columnSpanFull()
                        ->helperText('Bu türdeki ürünler (kendi varsayılan deposu seçilmemişse) stok işleminde ve üretimde bu depoyu kullanır. Her tür yalnızca bir depoda seçilebilir.'),
                    Toggle::make('allows_negative')
                        ->label('Stok eksiye düşebilir')
                        ->helperText('Açıksa bu depoda bakiye eksiye düşebilir (mamul hariç; mamul hiçbir depoda eksiye düşmez). Varsayılan: kapalı.'),
                    Toggle::make('is_production')
                        ->label('Üretim deposu')
                        ->helperText('Tek bir depo üretim deposu olabilir; işaretlenince diğerinden kaldırılır.'),
                    Toggle::make('is_shipping')
                        ->label('Sevkiyat deposu')
                        ->helperText('Tek bir depo sevkiyat deposu olabilir; işaretlenince diğerinden kaldırılır.'),
                ]),
        ]);
    }
}
