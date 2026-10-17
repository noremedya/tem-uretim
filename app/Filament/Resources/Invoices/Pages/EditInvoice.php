<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Concerns\TracksLockVersion;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Taslak başlığını düzenler. Satırlar detay sayfasında yönetilir (satır değişikliği faturanın sürümünü
 * artırdığından bu formda gösterilmez).
 */
class EditInvoice extends EditRecord
{
    use HandlesBusinessRules;
    use TracksLockVersion;

    protected static string $resource = InvoiceResource::class;

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();
        $data['type'] = $invoice->type()->value;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        // Para birimi veya tarih değiştiyse ve kur elle değiştirilmediyse yeni tarihin kuru doldurulur.
        $rateUntouched = filled($data['exchange_rate'] ?? null)
            && Money::compare(Money::parse($data['exchange_rate'], Money::RATE_SCALE), (string) $record->exchange_rate) === 0;
        $headerChanged = InvoiceForm::currency($data['currency'] ?? null) !== $record->currency
            || ($data['invoice_date'] ?? null) !== $record->invoice_date->toDateString();

        if ($rateUntouched && $headerChanged) {
            unset($data['exchange_rate']);
        }

        return $this->withBusinessRules(
            function () use ($record, $data): Model {
                $record = app(InvoiceService::class)->updateDraft($record, Arr::except($data, ['type']), $this->lockVersion);
                $this->lockVersion = $record->lock_version;

                return $record;
            },
            refreshUrl: static::getUrl(['record' => $record]),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
