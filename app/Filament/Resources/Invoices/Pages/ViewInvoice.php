<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\ExportType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\AuditTrailAction;
use App\Filament\Support\BusinessRuleNotification;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditTrailAction::make(),
            EditAction::make(),
            Action::make('issue')
                ->label('Faturayı kes')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->authorize('issue')
                ->requiresConfirmation()
                ->modalHeading('Faturayı kes')
                ->modalDescription(fn (Invoice $record): string => $record->type()->issuedByUs()
                    ? 'Fatura numarası verilir ve fatura kilitlenir. Kesilmiş fatura değiştirilemez, yalnızca iptal edilebilir.'
                    : 'Belge kaydedilir ve kilitlenir. Alış faturasında bağlı stok girişi olmayan satırlar için stok girişi yapılır.')
                ->action(function (Invoice $record, Action $action): void {
                    $this->run($action, fn () => app(InvoiceService::class)->issue($record, Auth::user()));
                    $record->refresh();
                    Notification::make()->success()->title("{$record->type()->label()} kesildi: {$record->displayNumber()}")->send();
                }),
            Action::make('cancel')
                ->label('İptal et')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('cancel')
                ->modalHeading('Faturayı iptal et')
                ->modalDescription('İptal geri alınamaz. Alış faturasında kesimde yapılan stok girişleri ters kaydedilir; faturadaki motorlar tekrar faturalanabilir.')
                ->schema([Textarea::make('reason')->label('İptal açıklaması')->required()->maxLength(1000)->rows(3)])
                ->action(function (Invoice $record, array $data, Action $action): void {
                    $this->run($action, fn () => app(InvoiceService::class)->cancel($record, Auth::user(), $data['reason']));
                    $record->refresh();
                    Notification::make()->success()->title('Fatura iptal edildi')->send();
                }),
            Action::make('customsDeclaration')
                ->label('Gümrük beyannamesi')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('gray')
                ->authorize('recordCustomsDeclaration')
                ->modalHeading('Gümrük beyannamesi')
                ->modalDescription(fn (Invoice $record): string => $record->export_type === ExportType::RegisteredExport
                    ? 'İhracatçının malı ihraç ettiği gümrük beyannamesi. Son gün: '.$record->exportDeadline()->format('d.m.Y').'.'
                    : 'Bu faturanın gümrük çıkış beyannamesi.')
                ->fillForm(fn (Invoice $record): array => [
                    'number' => $record->customs_declaration_number,
                    'date' => $record->customs_declaration_date?->toDateString(),
                ])
                ->schema([
                    TextInput::make('number')->label('Beyanname no')->required()->maxLength(30),
                    DatePicker::make('date')->label('Beyanname tarihi')->required()->native(false)->displayFormat('d.m.Y')->maxDate(today()),
                ])
                ->action(function (Invoice $record, array $data, Action $action): void {
                    $this->run($action, fn () => app(InvoiceService::class)->recordCustomsDeclaration($record, $data['number'], $data['date'], Auth::user()));
                    $record->refresh();
                    Notification::make()->success()->title('Gümrük beyannamesi kaydedildi')->send();
                }),
            Action::make('deleteDraft')
                ->label('Taslağı sil')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->authorize('delete')
                ->requiresConfirmation()
                ->action(function (Invoice $record, Action $action): void {
                    $this->run($action, fn () => app(InvoiceService::class)->deleteDraft($record));
                    Notification::make()->success()->title('Taslak silindi')->send();
                    $this->redirect(InvoiceResource::getUrl('index'));
                }),
        ];
    }

    private function run(Action $action, \Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $action->halt();
        }
    }
}
