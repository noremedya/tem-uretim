<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\User;
use App\Support\AuditPresenter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Denetim izi (yalnızca yönetici, salt okunur): kim neyi ne zaman değiştirdi; girişler dahil.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Yönetim';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'denetim kaydı';

    protected static ?string $pluralModelLabel = 'denetim izi';

    protected static ?string $navigationLabel = 'Denetim izi';

    protected static ?string $slug = 'denetim-izi';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Tarih')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('causer.name')->label('Kullanıcı')->placeholder('Sistem / anonim'),
                TextColumn::make('subject_type')->label('Kayıt türü')->formatStateUsing(fn (?string $state): string => AuditPresenter::subjectType($state))
                    ->placeholder('—'),
                TextColumn::make('subject_label')->label('Kayıt')->state(fn (Activity $record): string => AuditPresenter::subject($record)),
                TextColumn::make('event_label')->label('İşlem')->badge()->state(fn (Activity $record): string => AuditPresenter::event($record))
                    ->color(fn (Activity $record): string => match ($record->event) {
                        'created' => 'success', 'deleted', 'failed' => 'danger', 'login', 'logout' => 'gray', default => 'info',
                    }),
                TextColumn::make('changed_fields')->label('Değişen alanlar')->wrap()
                    ->state(fn (Activity $record): string => collect(AuditPresenter::changes($record))->pluck('field')->implode(', ') ?: '—'),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Kayıt türü')->options(AuditPresenter::SUBJECT_LABELS),
                SelectFilter::make('causer_id')->label('Kullanıcı')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->where('causer_type', 'user')->where('causer_id', $data['value'])
                        : $query),
                SelectFilter::make('event')->label('İşlem')->options(AuditPresenter::EVENT_LABELS),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('Başlangıç')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Bitiş')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('Tarih')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('causer.name')->label('Kullanıcı')->placeholder('Sistem / anonim'),
                TextEntry::make('subject_type')->label('Kayıt')->state(fn (Activity $record): string => AuditPresenter::subjectType($record->subject_type).' — '.AuditPresenter::subject($record)),
                TextEntry::make('event')->label('İşlem')->state(fn (Activity $record): string => AuditPresenter::event($record)),
                TextEntry::make('ip')->label('IP')->state(fn (Activity $record): ?string => $record->getProperty('ip'))->placeholder('—'),
            ]),
            Section::make('Değişiklikler')->columnSpanFull()
                ->visible(fn (Activity $record): bool => AuditPresenter::changes($record) !== [])
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): array => AuditPresenter::changes($record))
                        ->columns(3)
                        ->schema([
                            TextEntry::make('field')->label('Alan'),
                            TextEntry::make('old')->label('Eski'),
                            TextEntry::make('new')->label('Yeni'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
