<?php

namespace App\Filament\Resources\MotorModels\Schemas;

use App\Enums\MotorPhase;
use App\Support\Quantity;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MotorModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Motor modeli')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Kod')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Ad')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Açıklama')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Teknik özellikler')
                    ->description('Tüm alanlar isteğe bağlıdır.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('power_kw')
                            ->label('Güç')
                            ->suffix('kW')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn ($state): ?string => Quantity::toInput($state))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0)) {
                                    $fail('Geçerli bir güç girin (ör. 5,5; en fazla 3 ondalık).');
                                }
                            }),
                        TextInput::make('speed_rpm')
                            ->label('Devir')
                            ->suffix('d/dk')
                            ->integer()
                            ->minValue(1),
                        TextInput::make('voltage')
                            ->label('Gerilim')
                            ->maxLength(50)
                            ->placeholder('ör. 230/400 V'),
                        TextInput::make('frequency_hz')
                            ->label('Frekans')
                            ->suffix('Hz')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767),
                        TextInput::make('pole_count')
                            ->label('Kutup sayısı')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(32767),
                        Select::make('phase')
                            ->label('Faz')
                            ->options(MotorPhase::class),
                        TextInput::make('frame_size')
                            ->label('Gövde ölçüsü')
                            ->maxLength(50)
                            ->placeholder('ör. 112M'),
                        TextInput::make('mounting_type')
                            ->label('Montaj şekli')
                            ->maxLength(50)
                            ->placeholder('ör. B3, B5, B14'),
                        TextInput::make('protection_class')
                            ->label('Koruma sınıfı')
                            ->maxLength(20)
                            ->placeholder('ör. IP55'),
                        TextInput::make('efficiency_class')
                            ->label('Verim sınıfı')
                            ->maxLength(20)
                            ->placeholder('ör. IE3'),
                    ]),
            ]);
    }
}
