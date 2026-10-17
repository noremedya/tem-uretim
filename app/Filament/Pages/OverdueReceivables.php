<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Customers\CustomerResource;
use App\Services\StatementService;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Vadesi geçmiş alacaklar: cari ve para birimi başına, açık belgelerden (önce elle bağlama, sonra FIFO) hesaplanır.
 */
class OverdueReceivables extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Vadesi geçmiş alacaklar';

    protected static ?string $title = 'Vadesi geçmiş alacaklar';

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permission::FinanceManage) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(app(StatementService::class)->overdueReceivables())
                ->mapWithKeys(fn (array $row): array => ["{$row['customer']->getKey()}-{$row['currency']->value}" => [
                    'customer_id' => $row['customer']->getKey(),
                    'customer' => $row['customer']->name,
                    'currency' => $row['currency']->value,
                    'overdue' => $row['overdue'],
                    'oldest_due' => $row['oldest_due']->format('d.m.Y'),
                    'days' => (int) $row['oldest_due']->diffInDays(today()),
                ]])
                ->sortByDesc('days')
                ->all())
            ->columns([
                TextColumn::make('customer')->label('Cari')
                    ->url(fn (array $record): string => CustomerResource::getUrl('view', ['record' => $record['customer_id']])),
                TextColumn::make('overdue')->label('Vadesi geçen tutar')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state, array $record): string => Money::format($state, $record['currency'])),
                TextColumn::make('oldest_due')->label('En eski vade'),
                TextColumn::make('days')->label('Gecikme (gün)')->alignEnd()->color('danger'),
            ])
            ->emptyStateHeading('Vadesi geçmiş alacak yok');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
