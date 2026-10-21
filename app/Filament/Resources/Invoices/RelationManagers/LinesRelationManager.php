<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Enums\ExportType;
use App\Enums\InvoiceType;
use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MotorUnit;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Support\Gtip;
use App\Support\Money;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Fatura satırları. Taslakta yönetilir; kesilmiş faturada salt okunur. Tüm değişiklikler InvoiceService üzerinden.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Satırlar';

    protected static ?string $modelLabel = 'satır';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (Auth::user()?->can('update', $this->getOwnerRecord()) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['motorUnits', 'stockMovements', 'invoice']))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('description')
                    ->label('Açıklama')
                    ->description(fn (InvoiceLine $record): ?string => $this->lineDetail($record))
                    ->wrap(),
                TextColumn::make('quantity')->label('Miktar')->alignEnd()
                    ->formatStateUsing(fn ($state, InvoiceLine $record): string => Quantity::format($state, $record->unit)),
                TextColumn::make('unit_price')->label('Birim fiyat')->alignEnd()
                    ->formatStateUsing(fn ($state, InvoiceLine $record): string => Money::format($state, $record->invoice->currency->value, scale: 4)),
                TextColumn::make('discount_rate')->label('İskonto')->alignEnd()
                    ->formatStateUsing(fn ($state, InvoiceLine $record): string => '%'.Quantity::format((string) $state).' ('.Money::format((string) $record->discount_amount).')'),
                TextColumn::make('line_net')->label('Net')->alignEnd()->formatStateUsing(fn ($state): string => Money::format($state)),
                TextColumn::make('vat_rate')->label('KDV')->alignEnd()
                    ->formatStateUsing(fn ($state, InvoiceLine $record): string => '%'.Quantity::format((string) $state).' ('.Money::format((string) $record->vat_amount).')'),
                TextColumn::make('line_total')->label('Toplam')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state, InvoiceLine $record): string => Money::format($state, $record->invoice->currency->value)),
            ])
            ->headerActions([
                Action::make('addMotors')
                    ->label('Motor ekle')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->visible(fn (): bool => ! $this->isReadOnly() && $this->invoice()->type() === InvoiceType::SalesInvoice)
                    ->modalHeading('Sevk edilen motorlardan satır ekle')
                    ->schema([
                        Select::make('motor_model_id')
                            ->label('Model')
                            ->required()
                            ->live()
                            ->options(fn (): array => app(InvoiceService::class)->invoiceableMotors($this->invoice())
                                ->groupBy('motor_model_id')
                                ->map(fn ($units) => "{$units->first()->motorModel->code} — {$units->first()->motorModel->name} ({$units->count()} motor)")
                                ->all()),
                        CheckboxList::make('motor_unit_ids')
                            ->label('Seri numaraları')
                            ->required()
                            ->bulkToggleable()
                            ->columns(3)
                            ->options(fn (Get $get): array => blank($get('motor_model_id')) ? [] : app(InvoiceService::class)
                                ->invoiceableMotors($this->invoice(), (int) $get('motor_model_id'))
                                ->mapWithKeys(fn (MotorUnit $unit) => [$unit->getKey() => $unit->serial_number])
                                ->all()),
                        $this->priceInput(required: false)->helperText('Boş bırakılırsa müşteri veya liste fiyatı kullanılır (fatura para birimindeyse).'),
                        $this->rateInput('discount_rate', 'İskonto (%)', '0')->datalist(['15', '20', '30']),
                        $this->vatInput(),
                    ])
                    ->action(fn (array $data, Action $action) => $this->guard($action, fn () => app(InvoiceService::class)->addMotorLine(
                        $this->invoice(), $data['motor_unit_ids'], $data['unit_price'] ?? null, $data['discount_rate'] ?? 0, $data['vat_rate'] ?? InvoiceService::DEFAULT_VAT_RATE,
                    ))),
                Action::make('addLine')
                    ->label(fn (): string => $this->isPurchaseInvoice() ? 'Malzeme satırı ekle' : 'Satır ekle')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->modalHeading(fn (): string => $this->isPurchaseInvoice() ? 'Malzeme satırı ekle' : 'Satır ekle (nakliye, hizmet vb.)')
                    ->schema(fn (): array => $this->lineFields(null))
                    ->action(fn (array $data, Action $action) => $this->guard($action, fn () => app(InvoiceService::class)->addLine($this->invoice(), $data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (InvoiceLine $record): array => $record->motor_model_id !== null
                        ? [$this->priceInput(required: true), $this->rateInput('discount_rate', 'İskonto (%)', '0')->datalist(['15', '20', '30']), $this->vatInput()]
                        : $this->lineFields($record))
                    ->fillForm(fn (InvoiceLine $record): array => [
                        'part_id' => $record->part_id,
                        'warehouse_id' => $record->warehouse_id,
                        'description' => $record->description,
                        'quantity' => Quantity::toInput((string) $record->quantity),
                        'unit' => $record->unit,
                        'unit_price' => Money::toInput((string) $record->unit_price, 4),
                        'discount_rate' => Quantity::toInput((string) $record->discount_rate),
                        'vat_rate' => Quantity::toInput((string) $record->vat_rate),
                        'gtip_code' => Gtip::format($record->gtip_code),
                        'stock_movement_ids' => $record->stockMovements->pluck('id')->all(),
                    ])
                    ->using(fn (InvoiceLine $record, array $data, Action $action): Model => $this->guard($action, fn () => app(InvoiceService::class)->updateLine($record, $data))),
                DeleteAction::make()
                    ->using(fn (InvoiceLine $record, Action $action): bool => $this->guard($action, function () use ($record): bool {
                        app(InvoiceService::class)->removeLine($record);

                        return true;
                    })),
            ]);
    }

    private function invoice(): Invoice
    {
        /** @var Invoice */
        return $this->getOwnerRecord();
    }

    private function isPurchaseInvoice(): bool
    {
        return $this->invoice()->type() === InvoiceType::PurchaseInvoice;
    }

    /** @return array<int, mixed> */
    private function lineFields(?InvoiceLine $line): array
    {
        if ($this->isPurchaseInvoice()) {
            return [
                Select::make('part_id')
                    ->label('Malzeme')
                    ->required()
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => Part::query()
                        ->where('type', '!=', PartType::FinishedGood->value)
                        ->where('is_active', true)
                        ->where(fn (Builder $q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")->orWhere('barcode', $search))
                        ->orderBy('code')->limit(50)->get()
                        ->mapWithKeys(fn (Part $p) => [$p->getKey() => "{$p->code} — {$p->name}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($p = Part::query()->find($value)) ? "{$p->code} — {$p->name}" : null)
                    ->afterStateUpdated(fn ($state, Set $set) => $set('warehouse_id', filled($state) ? Part::query()->find($state)?->defaultWarehouseId() : null)),
                Select::make('warehouse_id')
                    ->label('Giriş deposu')
                    ->options(fn (?InvoiceLine $record): array => Warehouse::query()
                        ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $record?->warehouse_id))
                        ->orderBy('code')->pluck('name', 'id')->all())
                    ->placeholder('Ürünün varsayılan deposu')
                    ->helperText('Stok girişi seçilmezse kesimde bu depoya giriş yapılır.'),
                $this->quantityInput(),
                $this->priceInput(required: true),
                $this->rateInput('discount_rate', 'İskonto (%)', '0')->datalist(['15', '20', '30']),
                $this->vatInput(),
                CheckboxList::make('stock_movement_ids')
                    ->label('Bağlanacak stok girişleri')
                    ->helperText('Malzeme depoya önceden girildiyse girişleri seçin (toplam satır miktarına eşit olmalı). Seçilmezse fatura kesilirken stok girişi yapılır.')
                    ->columnSpanFull()
                    ->options(fn (Get $get): array => blank($get('part_id')) ? [] : app(InvoiceService::class)
                        ->linkableReceipts((int) $get('part_id'), $line)
                        ->mapWithKeys(fn (StockMovement $m) => [$m->getKey() => sprintf('%s — %s — %s%s', $m->created_at->format('d.m.Y H:i'), $m->warehouse->name, Quantity::format((string) $m->quantity), $m->description ? " — {$m->description}" : '')])
                        ->all()),
            ];
        }

        return [
            TextInput::make('description')->label('Açıklama')->required()->maxLength(255)->columnSpanFull(),
            $this->quantityInput(),
            TextInput::make('unit')->label('Birim')->default('Adet')->required()->maxLength(50),
            $this->priceInput(required: true),
            $this->rateInput('discount_rate', 'İskonto (%)', '0')->datalist(['15', '20', '30']),
            $this->vatInput(),
            TextInput::make('gtip_code')
                ->label('GTİP')
                ->maxLength(20)
                ->placeholder('ör. 8501.52.20.00.00')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! Gtip::isValid($value)) {
                        $fail(Gtip::INVALID_MESSAGE);
                    }
                })
                ->helperText('Mal satırında (ihracat) gerekir; nakliye/hizmet satırında boş bırakılır.'),
        ];
    }

    /** İhracat (11/1-a) faturasında KDV %0'dır ve değiştirilemez. */
    private function vatInput(): TextInput
    {
        $export = $this->invoice()->export_type === ExportType::Export;

        return $this->rateInput('vat_rate', 'KDV (%)', $export ? '0' : InvoiceService::DEFAULT_VAT_RATE)
            ->disabled($export)
            ->helperText($export ? 'İhracat faturası: KDV %0 (KDV 11/1-a istisnası).' : null);
    }

    private function quantityInput(): TextInput
    {
        return TextInput::make('quantity')
            ->label('Miktar')
            ->required()
            ->inputMode('decimal')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0) {
                    $fail('Sıfırdan büyük geçerli bir miktar girin.');
                }
            });
    }

    private function priceInput(bool $required): TextInput
    {
        return TextInput::make('unit_price')
            ->label('Birim fiyat (KDV hariç)')
            ->required($required)
            ->inputMode('decimal')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! Money::isValid($value, 4)) {
                    $fail('Geçerli bir tutar girin (ör. 1.250,50; en fazla 4 ondalık).');
                }
            });
    }

    private function rateInput(string $name, string $label, string $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->default($default)
            ->inputMode('decimal')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValid($value) || Money::compare(Money::parse($value), '0') < 0 || Money::compare(Money::parse($value), '100') > 0) {
                    $fail('0 ile 100 arasında bir oran girin.');
                }
            });
    }

    private function lineDetail(InvoiceLine $line): ?string
    {
        $detail = match (true) {
            $line->motorUnits->isNotEmpty() => 'Seri no: '.$line->motorUnits->pluck('serial_number')->sort()->implode(', '),
            $line->stockMovements->isNotEmpty() => sprintf('%d stok girişine bağlı', $line->stockMovements->count()),
            default => null,
        };

        $gtip = $line->gtip_code ? 'GTİP: '.Gtip::format($line->gtip_code) : null;

        return implode(' · ', array_filter([$gtip, $detail])) ?: null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guard(Action $action, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }
    }
}
