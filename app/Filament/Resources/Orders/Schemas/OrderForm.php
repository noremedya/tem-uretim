<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Services\CustomerService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Sipariş formu. Kalemler Repeater ile girilir ama relationship() kullanılmaz: kayıt OrderService üzerinden
 * yapılır (kurallar ve sürüm kontrolü). Müşteri ve kalemler yalnızca sipariş açıkken değişir.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sipariş')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Müşteri')
                            // Pasif müşteri seçilemez; düzenlemede mevcut müşteri listede kalır.
                            ->relationship(
                                'customer',
                                'name',
                                modifyQueryUsing: fn (Builder $query, ?Order $record) => $query
                                    ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $record?->customer_id)),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (): ?int => request()->integer('customer') ?: null)
                            ->disabled(fn (?Order $record): bool => static::itemsLocked($record))
                            ->createOptionForm(CustomerForm::fields())
                            ->createOptionUsing(fn (array $data): int => app(CustomerService::class)->create($data)->getKey())
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->modalHeading('Yeni müşteri')
                                ->modalSubmitActionLabel('Müşteriyi oluştur')
                                ->visible(fn (): bool => Auth::user()?->can('create', Customer::class) ?? false)),
                        TextInput::make('customer_reference')
                            ->label('Müşteri sipariş no')
                            ->maxLength(100)
                            ->helperText('İsteğe bağlı. Müşterinin kendi sipariş numarası.'),
                        DatePicker::make('order_date')
                            ->label('Sipariş tarihi')
                            ->required()
                            ->default(fn (): string => today()->toDateString())
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->disabled(fn (?Order $record): bool => static::itemsLocked($record)),
                        DatePicker::make('due_date')
                            ->label('Termin')
                            ->required()
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->afterOrEqual('order_date')
                            ->validationMessages([
                                'after_or_equal' => 'Termin tarihi sipariş tarihinden önce olamaz.',
                            ]),
                        Textarea::make('notes')
                            ->label('Notlar')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Kalemler')
                    ->columnSpanFull()
                    ->description(fn (?Order $record): ?string => static::itemsLocked($record)
                        ? 'Kalemler yalnızca sipariş açıkken değiştirilebilir.'
                        : null)
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                Repeater\TableColumn::make('Motor modeli'),
                                Repeater\TableColumn::make('Adet')->width('10rem'),
                            ])
                            ->schema([
                                Select::make('motor_model_id')
                                    ->hiddenLabel()
                                    ->options(fn (?Order $record): array => static::motorModelOptions($record))
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->validationMessages([
                                        'distinct' => 'Aynı motor modeli birden fazla kalemde seçilemez.',
                                    ]),
                                TextInput::make('quantity')
                                    ->hiddenLabel()
                                    ->required()
                                    ->integer()
                                    ->minValue(1),
                            ])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->required()
                            ->addActionLabel('Kalem ekle')
                            ->reorderable(false)
                            ->validationMessages([
                                'required' => 'Siparişte en az bir kalem olmalıdır.',
                                'min' => 'Siparişte en az bir kalem olmalıdır.',
                            ])
                            ->disabled(fn (?Order $record): bool => static::itemsLocked($record)),
                    ]),
            ]);
    }

    /** Kısmi siparişte müşteri, sipariş tarihi ve kalemler kilitlidir. */
    private static function itemsLocked(?Order $record): bool
    {
        return $record !== null && ! $record->status->allowsItemChanges();
    }

    /**
     * Aktif motor modelleri; düzenlemede siparişte zaten olan (sonradan pasifleşmiş olabilecek) modeller de.
     *
     * @return array<int, string>
     */
    private static function motorModelOptions(?Order $record): array
    {
        $current = $record?->items()->pluck('motor_model_id')->all() ?? [];

        return MotorModel::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereIn('id', $current))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (MotorModel $model): array => [$model->id => "{$model->code} — {$model->name}"])
            ->all();
    }
}
