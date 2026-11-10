<?php

namespace App\Filament\Resources\MotorDesigns\Pages;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Filament\Resources\MotorModels\MotorModelResource;
use App\Filament\Support\AuditTrailAction;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\MotorDesign;
use App\Models\MotorModel;
use App\Services\MotorDesignService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewMotorDesign extends ViewRecord
{
    protected static string $resource = MotorDesignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convert')
                ->label('Motor modeline çevir')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('success')
                ->visible(fn (MotorDesign $record): bool => $record->motor_model_id === null && $record->is_active && Auth::user()->can('create', MotorModel::class))
                ->modalDescription('Motor modeli, mamul kartı ve reçetesi oluşur. Kartı olmayan deneme bileşenleri reçeteye girmez; önce kartlarını açıp tasarımda seçin. Bir tasarım bir kez çevrilir.')
                ->fillForm(fn (MotorDesign $record): array => ['code' => $record->code, 'name' => $record->name])
                ->schema([
                    TextInput::make('code')->label('Motor kodu')->required()->maxLength(50),
                    TextInput::make('name')->label('Motor adı')->required()->maxLength(255),
                ])
                ->action(function (MotorDesign $record, array $data, Action $action): void {
                    try {
                        $result = app(MotorDesignService::class)->convertToModel($record, $data);
                    } catch (BusinessRuleException $exception) {
                        BusinessRuleNotification::send($exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title("Motor modeli oluşturuldu: {$result['model']->code}")
                        ->body("Reçetede {$result['added']} bileşen".($result['skipped'] ? ", {$result['skipped']} deneme bileşeni aktarılmadı." : '.'))->send();
                    $this->redirect(MotorModelResource::getUrl('view', ['record' => $result['model']]));
                }),
            EditAction::make(),
            AuditTrailAction::make(),
        ];
    }
}
