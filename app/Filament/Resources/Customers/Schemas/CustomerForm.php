<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Müşteri bilgileri')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema(static::fields()),
            ]);
    }

    /**
     * Müşteri alanları: müşteri kaynağında ve sipariş formundaki "+" (yeni müşteri) penceresinde ortak kullanılır.
     *
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label('Ad / ünvan')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('tax_number')
                ->label('Vergi no / TCKN')
                ->maxLength(20)
                // Boşluklar doğrulamadan ve kayıttan önce atılır ("123 456 7890" → "1234567890").
                ->mutateStateForValidationUsing(fn (?string $state): ?string => Customer::normalizeTaxNumber($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => Customer::normalizeTaxNumber($state))
                ->regex(Customer::TAX_NUMBER_PATTERN)
                ->validationMessages([
                    'regex' => 'Vergi no 10 (VKN) veya 11 (TCKN) haneli olmalı ve yalnızca rakam içermelidir.',
                    'unique' => 'Bu vergi numarasıyla kayıtlı bir müşteri zaten var.',
                ])
                ->unique(Customer::class, 'tax_number', ignoreRecord: true)
                ->helperText('İsteğe bağlı. Tüzel kişi için 10 haneli VKN, şahıs için 11 haneli TCKN.'),
            TextInput::make('tax_office')
                ->label('Vergi dairesi')
                ->maxLength(100),
            TextInput::make('phone')
                ->label('Telefon')
                ->tel()
                // Filament'in varsayılan kuralı "0 (532) 123 45 67" gibi yaygın biçimleri reddeder.
                ->telRegex('/^[0-9+()\s.\/-]*$/')
                ->maxLength(30),
            TextInput::make('email')
                ->label('E-posta')
                ->email()
                ->maxLength(255),
            Textarea::make('address')
                ->label('Adres')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }
}
