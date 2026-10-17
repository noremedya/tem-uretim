<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(InvoiceService::class)->createDraft($data));
    }

    /** Satırlar detay sayfasında eklenir. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
