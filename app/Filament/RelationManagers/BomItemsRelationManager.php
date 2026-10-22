<?php

namespace App\Filament\RelationManagers;

use App\Enums\PartType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\BomEditor;
use App\Filament\Support\BomLineForm;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\BomItem;
use App\Models\MotorModel;
use App\Models\Part;
use App\Services\BomService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reçete. Motor modeli sayfasında mamul kartının, yarı mamul kartı sayfasında kartın kendi reçetesini
 * gösterir. Değişiklikler yalnızca BomService üzerinden yapılır.
 */
class BomItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'bomItems';

    protected static ?string $title = 'Reçete';

    protected static ?string $modelLabel = 'reçete satırı';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        // Reçeteyi motor modeli görme yetkisi olan görür (operatör ve depo görmez; BomItemPolicy).
        return match (true) {
            $ownerRecord instanceof MotorModel => $user?->can(Permission::MotorModelsView) ?? false,
            $ownerRecord instanceof Part => $ownerRecord->type === PartType::SemiFinished
                && ($user?->can(Permission::PartsView) ?? false)
                && ($user?->can('viewAny', BomItem::class) ?? false),
            default => false,
        };
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can(Permission::MotorModelsManage) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['component.unit', 'scrapPart']))
            ->defaultSort('id')
            ->columns(BomLineForm::columns())
            ->headerActions([
                Action::make('editor')
                    ->label('Reçete ekranında aç')
                    ->icon('heroicon-o-rectangle-stack')
                    ->color('gray')
                    ->url(fn (): string => BomEditor::getUrl(['part' => $this->parentPart()->getKey()])),
                Action::make('addLine')
                    ->label('Bileşen ekle')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->schema(fn (): array => BomLineForm::fields(fn () => $this->parentPart(), creating: true))
                    ->action(fn (array $data, Action $action) => $this->guard($action, fn () => BomLineForm::save($this->parentPart(), $data))),
            ])
            ->recordActions([
                Action::make('editLine')
                    ->label('Düzenle')
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->fillForm(fn (BomItem $record): array => BomLineForm::fill($record))
                    ->schema(fn (): array => BomLineForm::fields(fn () => $this->parentPart(), creating: false))
                    ->action(fn (BomItem $record, array $data, Action $action) => $this->guard($action, fn () => BomLineForm::save($this->parentPart(), $data, $record))),
                DeleteAction::make()
                    ->label('Kaldır')
                    ->modalHeading('Bileşeni reçeteden kaldır')
                    ->modalDescription('Açık iş emirleri kendi malzeme listesini kullandığı için etkilenmez.')
                    ->using(fn (BomItem $record, Action $action): bool => $this->guard($action, function () use ($record): bool {
                        app(BomService::class)->remove($record);

                        return true;
                    })),
            ]);
    }

    private function parentPart(): Part
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof MotorModel ? $owner->finishedPart()->firstOrFail() : $owner;
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
