<?php

namespace App\Filament\Resources\Parts\RelationManagers;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Part;
use App\Models\StockBalance;
use App\Services\StockService;
use App\Support\Quantity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ürünün depo bazlı bakiyeleri ve kritik seviyeleri. Kritik seviyeyi ürünleri yöneten (yönetici) değiştirir.
 */
class StockBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'stockBalances';

    protected static ?string $title = 'Depo bakiyeleri';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can(Permission::PartsView) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Part $part */
        $part = $this->getOwnerRecord()->loadMissing('unit');
        $defaultWarehouseId = $part->defaultWarehouseId();
        $unit = $part->unit->name;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('warehouse')
                ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
                ->select('stock_balances.*')
                ->orderBy('warehouses.code'))
            ->paginated(false)
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Depo')
                    ->description(fn (StockBalance $record): ?string => $record->warehouse_id === $defaultWarehouseId ? 'Varsayılan depo' : null),
                TextColumn::make('quantity')
                    ->label('Bakiye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Quantity::format($state, $unit))
                    ->color(fn (StockBalance $record): ?string => $record->isCriticalAt() || Quantity::compare((string) $record->quantity, '0') < 0 ? 'danger' : null)
                    ->weight(fn (StockBalance $record): ?string => $record->isCriticalAt() ? 'bold' : null),
                TextColumn::make('critical_level')
                    ->label('Kritik seviye')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockBalance $record): string => $record->tracksCriticalLevel() ? Quantity::format($state, $unit) : 'Takip yok'),
            ])
            ->recordActions([
                Action::make('criticalLevel')
                    ->label('Kritik seviye')
                    ->icon('heroicon-m-bell-alert')
                    ->visible(fn (): bool => auth()->user()?->can(Permission::PartsManage) ?? false)
                    ->modalHeading(fn (StockBalance $record): string => "Kritik seviye — {$record->warehouse->name}")
                    ->fillForm(fn (StockBalance $record): array => ['critical_level' => Quantity::toInput($record->critical_level)])
                    ->schema([
                        TextInput::make('critical_level')
                            ->label('Kritik seviye')
                            ->required()
                            ->inputMode('decimal')
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') < 0) {
                                    $fail('Geçerli bir miktar girin (ör. 10 veya 12,5; en fazla 3 ondalık).');
                                }
                            })
                            ->helperText('Bu depodaki bakiye bu değere eşit veya altındaysa kritik sayılır. 0 = takip yok.'),
                    ])
                    ->action(function (StockBalance $record, array $data, Action $action) use ($part): void {
                        try {
                            app(StockService::class)->setCriticalLevel($part, Quantity::parse($data['critical_level']), $record->warehouse);
                        } catch (BusinessRuleException $e) {
                            BusinessRuleNotification::send($e);
                            $action->halt();
                        }

                        Notification::make()->success()->title('Kritik seviye kaydedildi')->send();
                    }),
            ]);
    }
}
