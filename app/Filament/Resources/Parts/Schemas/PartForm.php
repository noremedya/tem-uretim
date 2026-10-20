<?php

namespace App\Filament\Resources\Parts\Schemas;

use App\Enums\PartType;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Units\Schemas\UnitForm;
use App\Models\Brand;
use App\Models\Part;
use App\Models\Unit;
use App\Services\BrandService;
use App\Services\PartService;
use App\Services\UnitService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                Section::make('Ürün')
                    ->columns(2)
                    ->schema([
                        // Mamul kartının kodu, adı ve birimi motor modelinden yönetilir.
                        TextInput::make('code')
                            ->label('Kod')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Part $record): bool => $record?->isFinishedGood() ?? false)
                            ->helperText(fn (?Part $record): ?string => ($record?->isFinishedGood() ?? false)
                                ? 'Mamul kartının kodu, adı ve birimi motor modelinden değiştirilir.'
                                : null),
                        TextInput::make('name')
                            ->label('Ad')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (?Part $record): bool => $record?->isFinishedGood() ?? false),
                        // Muhasebe koduyla gösterilir. Mamul kartı elle açılamaz ve mamul türüne alınamaz
                        // (PartService'te de kontrol edilir); mamul kartının türü değiştirilemez.
                        Select::make('type')
                            ->label('Tür')
                            ->options(fn (?Part $record): array => $record?->type === PartType::FinishedGood
                                ? [PartType::FinishedGood->value => PartType::FinishedGood->getLabel()]
                                : PartType::manualOptions())
                            ->disabled(fn (?Part $record): bool => $record?->type === PartType::FinishedGood)
                            ->required()
                            ->helperText('Mamul kartları motor modeliyle birlikte oluşturulur.'),
                        Select::make('unit_id')
                            ->label('Birim')
                            ->relationship('unit', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            // Hareketi olan parçanın birimi değiştirilemez (PartService'te de kontrol edilir).
                            ->disabled(fn (?Part $record): bool => ($record?->isFinishedGood() ?? false) || ($record?->stockMovements()->exists() ?? false))
                            ->helperText(fn (?Part $record): ?string => ($record?->stockMovements()->exists() ?? false)
                                ? 'Stok hareketi olan ürünün birimi değiştirilemez.'
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
                        Select::make('brand_id')
                            ->label('Marka')
                            ->relationship('brand', 'name', modifyQueryUsing: fn ($query, ?Part $record) => $query
                                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $record?->brand_id)))
                            ->searchable()
                            ->preload()
                            ->createOptionForm(BrandForm::fields())
                            ->createOptionUsing(fn (array $data): int => app(BrandService::class)->create($data)->getKey())
                            ->createOptionAction(fn (Action $action) => $action->modalHeading('Yeni marka')
                                ->visible(fn (): bool => Auth::user()?->can('create', Brand::class) ?? false))
                            ->helperText('İsteğe bağlı. Yeni marka "+" ile veya Markalar ekranından eklenir.'),
                        Select::make('default_warehouse_id')
                            ->label('Varsayılan depo')
                            ->relationship('defaultWarehouse', 'name', modifyQueryUsing: fn ($query, ?Part $record) => $query
                                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $record?->default_warehouse_id)))
                            ->preload()
                            ->hidden(fn (?Part $record): bool => $record?->isFinishedGood() ?? false)
                            ->placeholder('Türün varsayılan deposu')
                            ->helperText('Boşsa türün varsayılan deposu (Depolar ekranı). Stok işleminde depo seçilmezse bu depo kullanılır.'),
                        TextInput::make('critical_level')
                            ->label('Kritik seviye (varsayılan depo)')
                            ->required()
                            ->default('0')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn ($state, ?Part $record): ?string => Quantity::toInput($record?->exists ? $record->criticalLevel() : $state))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') < 0) {
                                    $fail('Geçerli bir miktar girin (ör. 10 veya 12,5; en fazla 3 ondalık).');
                                }
                            })
                            ->helperText('Varsayılan depodaki bakiye bu değere eşit veya altındaysa kritik sayılır. 0 = takip yok. Diğer depolarınki ürün sayfasındaki "Depo bakiyeleri"nden.'),
                        Textarea::make('description')
                            ->label('Açıklama')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        // Görsel sunucunun kendi diskinde tutulur (CDN yok); yüklerken uzun kenar 1200 px'e küçültülür.
                        FileUpload::make('image_path')
                            ->label('Görsel')
                            ->image()
                            ->disk(PartService::IMAGE_DISK)
                            ->directory(PartService::IMAGE_DIRECTORY)
                            ->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(5120)
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1200')
                            ->imageResizeTargetHeight('1200')
                            ->imageResizeUpscale(false)
                            ->imagePreviewHeight('160')
                            ->columnSpanFull()
                            ->helperText('JPG, PNG veya WebP; en fazla 5 MB. Ürünün kendi fotoğrafını yükleyin.'),
                    ]),
            ]);
    }
}
