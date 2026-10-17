<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\AccountTransactionType;
use App\Enums\Currency;
use App\Enums\Permission;
use App\Models\AccountTransaction;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Cari ekstre (salt okunur): borç/alacak hareketleri. Yürüyen bakiye para birimi filtresiyle anlamlıdır. */
class AccountTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'accountTransactions';

    protected static ?string $title = 'Cari ekstre';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can(Permission::FinanceManage) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('transaction_date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('transaction_date')->label('Tarih')->date('d.m.Y'),
                TextColumn::make('type')->label('Tür')->badge(),
                TextColumn::make('description')->label('Açıklama')->wrap()->placeholder('—'),
                TextColumn::make('due_date')->label('Vade')->date('d.m.Y')->placeholder('—'),
                TextColumn::make('debit')->label('Borç')->alignEnd()
                    ->state(fn (AccountTransaction $r): ?string => Money::compare((string) $r->amount, '0') > 0 ? Money::format((string) $r->amount, $r->currency->value) : null),
                TextColumn::make('credit')->label('Alacak')->alignEnd()
                    ->state(fn (AccountTransaction $r): ?string => Money::compare((string) $r->amount, '0') < 0 ? Money::format(ltrim((string) $r->amount, '-'), $r->currency->value) : null),
            ])
            ->filters([
                SelectFilter::make('currency')->label('Para birimi')->options(Currency::class),
                SelectFilter::make('type')->label('Tür')->options(AccountTransactionType::class),
            ]);
    }
}
