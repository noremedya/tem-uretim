<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\TaxNumber;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

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
                ->columnSpanFull()
                // Aynı adla aktif müşteri varsa uyarı: kayıt engellenmez, aşağıdaki onay kutusu istenir.
                ->live(onBlur: true)
                ->hint(fn (?string $state, ?Customer $record): ?string => static::duplicatesOf($state, $record)->isNotEmpty()
                    ? CustomerService::DUPLICATE_NAME_MESSAGE
                    : null)
                ->hintIcon('heroicon-m-exclamation-triangle')
                ->hintColor('warning'),
            Checkbox::make('confirm_duplicate_name')
                ->label('Aynı adla yeni kayıt yapmak istiyorum')
                ->helperText(fn (Get $get, ?Customer $record): string => 'Mevcut: '.static::duplicatesOf($get('name'), $record)
                    ->map(fn (Customer $c): string => $c->tax_number ? "{$c->name} (vergi no {$c->tax_number})" : $c->name)
                    ->implode(', '))
                ->visible(fn (Get $get, ?Customer $record): bool => static::duplicatesOf($get('name'), $record)->isNotEmpty())
                ->accepted()
                ->validationMessages([
                    'accepted' => CustomerService::DUPLICATE_NAME_MESSAGE.'. Devam etmek için onaylayın.',
                ])
                ->columnSpanFull(),
            TextInput::make('tax_number')
                ->label('Vergi no / TCKN')
                ->maxLength(20)
                // Boşluklar doğrulamadan ve kayıttan önce atılır ("123 456 7890" → "1234567890").
                ->mutateStateForValidationUsing(fn (?string $state): ?string => Customer::normalizeTaxNumber($state))
                ->dehydrateStateUsing(fn (?string $state): ?string => Customer::normalizeTaxNumber($state))
                ->regex(Customer::TAX_NUMBER_PATTERN)
                // Kontrol hanesi (VKN / TCKN algoritması); biçim hatalıysa yalnızca regex mesajı gösterilir.
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match(Customer::TAX_NUMBER_PATTERN, $value) && ! TaxNumber::isValid($value)) {
                        $fail(TaxNumber::INVALID_MESSAGE);
                    }
                })
                ->validationMessages([
                    'regex' => 'Vergi no 10 (VKN) veya 11 (TCKN) haneli olmalı ve yalnızca rakam içermelidir.',
                    'unique' => 'Bu vergi numarasıyla kayıtlı bir müşteri zaten var.',
                    'required_with' => 'Vergi dairesi girildiğinde vergi no da zorunludur.',
                ])
                ->unique(Customer::class, 'tax_number', ignoreRecord: true)
                // Vergi no ve vergi dairesi birlikte girilir (servis ve veritabanında da zorunlu).
                ->requiredWith('tax_office')
                ->helperText('İsteğe bağlı; girilirse vergi dairesi de zorunludur. Tüzel kişi için 10 haneli VKN, şahıs için 11 haneli TCKN.'),
            TextInput::make('tax_office')
                ->label('Vergi dairesi')
                ->maxLength(100)
                ->requiredWith('tax_number')
                ->validationMessages([
                    'required_with' => 'Vergi no girildiğinde vergi dairesi de zorunludur.',
                ]),
            TextInput::make('phone')
                ->label('Telefon')
                ->required()
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

    /** @return Collection<int, Customer> */
    private static function duplicatesOf(?string $name, ?Customer $record): Collection
    {
        return app(CustomerService::class)->duplicatesByName($name, $record);
    }
}
