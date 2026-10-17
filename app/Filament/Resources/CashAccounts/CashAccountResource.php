<?php

namespace App\Filament\Resources\CashAccounts;

use App\Enums\CashAccountKind;
use App\Enums\Currency;
use App\Filament\Resources\CashAccounts\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccounts\Pages\EditCashAccount;
use App\Filament\Resources\CashAccounts\Pages\ListCashAccounts;
use App\Filament\Resources\CashAccounts\Pages\ViewCashAccount;
use App\Filament\Resources\CashAccounts\RelationManagers\MovementsRelationManager;
use App\Models\CashAccount;
use App\Services\LedgerService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CashAccountResource extends Resource
{
    protected static ?string $model = CashAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Ön muhasebe';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'kasa/banka hesabı';

    protected static ?string $pluralModelLabel = 'kasa ve bankalar';

    protected static ?string $navigationLabel = 'Kasa ve bankalar';

    public static function form(Schema $schema): Schema
    {
        $isBank = fn (Get $get): bool => ($get('kind') instanceof CashAccountKind ? $get('kind') : CashAccountKind::tryFrom((string) $get('kind'))) === CashAccountKind::Bank;

        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                Select::make('kind')->label('Tür')->options(CashAccountKind::class)->required()->live()->disabledOn('edit'),
                Select::make('currency')->label('Para birimi')->options(Currency::class)->required()->default(Currency::TRY->value)->disabledOn('edit'),
                TextInput::make('name')->label('Hesap adı')->maxLength(100)->required($isBank)->placeholder('ör. Vadesiz TL'),
                TextInput::make('bank_name')->label('Banka')->maxLength(100)->required($isBank)->visible($isBank),
                TextInput::make('branch')->label('Şube')->maxLength(100)->visible($isBank),
                TextInput::make('iban')->label('IBAN')->maxLength(34)->visible($isBank)->placeholder('TR00 0000 0000 0000 0000 0000 00'),
                Toggle::make('is_active')->label('Aktif')->visibleOn('edit'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('name')->label('Hesap')->state(fn (CashAccount $record): string => $record->label())->weight('bold'),
                TextEntry::make('kind')->label('Tür')->badge(),
                TextEntry::make('iban')->label('IBAN')->placeholder('—'),
                TextEntry::make('balance')->label('Bakiye')->weight('bold')
                    ->state(fn (CashAccount $record): string => Money::format(app(LedgerService::class)->cashBalance($record), $record->currency->value)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('movements as balance', 'amount'))
            ->defaultSort('kind')
            ->columns([
                TextColumn::make('name')->label('Hesap')->state(fn (CashAccount $record): string => $record->label())->searchable(['name', 'bank_name']),
                TextColumn::make('kind')->label('Tür')->badge(),
                TextColumn::make('iban')->label('IBAN')->placeholder('—')->toggleable(),
                TextColumn::make('balance')->label('Bakiye')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state, CashAccount $record): string => Money::format((string) ($state ?? 0), $record->currency->value)),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashAccounts::route('/'),
            'create' => CreateCashAccount::route('/create'),
            'view' => ViewCashAccount::route('/{record}'),
            'edit' => EditCashAccount::route('/{record}/edit'),
        ];
    }
}
