<?php

namespace App\Filament\Resources\Parts\Schemas;

use App\Enums\PartType;
use App\Filament\Resources\Units\Schemas\UnitForm;
use App\Models\Part;
use App\Models\Unit;
use App\Services\UnitService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class PartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Stok kartı')
                    ->columns(2)
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
                        Select::make('type')
                            ->label('Tür')
                            ->options(PartType::class)
                            ->required(),
                        Select::make('unit_id')
                            ->label('Birim')
                            ->relationship('unit', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            // Hareketi olan parçanın birimi değiştirilemez (PartService'te de kontrol edilir).
                            ->disabled(fn (?Part $record): bool => $record?->stockMovements()->exists() ?? false)
                            ->helperText(fn (?Part $record): ?string => ($record?->stockMovements()->exists() ?? false)
                                ? 'Stok hareketi olan parçanın birimi değiştirilemez.'
                                : null)
                            // "+": formdan çıkmadan yeni birim; yalnızca birim yönetme yetkisi olanlar görür.
                            ->createOptionForm(UnitForm::fields())
                            ->createOptionUsing(fn (array $data): int => app(UnitService::class)->create($data)->getKey())
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->modalHeading('Yeni birim')
                                ->modalSubmitActionLabel('Birimi oluştur')
                                ->visible(fn (): bool => Auth::user()?->can('create', Unit::class) ?? false)),
                        TextInput::make('barcode')
                            ->label('Barkod')
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->helperText('İsteğe bağlı. USB okuyucuyla okutabilirsiniz.'),
                        TextInput::make('critical_level')
                            ->label('Kritik seviye')
                            ->required()
                            ->default('0')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn ($state): ?string => Quantity::toInput($state))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') < 0) {
                                    $fail('Geçerli bir miktar girin (ör. 10 veya 12,5; en fazla 3 ondalık).');
                                }
                            })
                            ->helperText('Bakiye bu değere eşit veya altındaysa kritik sayılır. 0 = takip yok.'),
                    ]),
            ]);
    }
}
