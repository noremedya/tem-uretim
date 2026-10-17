<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Concerns\HandlesBusinessRules;
use App\Filament\Resources\Payments\PaymentResource;
use App\Services\PaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePayment extends CreateRecord
{
    use HandlesBusinessRules;

    protected static string $resource = PaymentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withBusinessRules(fn () => app(PaymentService::class)->record([
            ...$data,
            'allocations' => array_values($data['allocations'] ?? []),
        ], Auth::user()));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
