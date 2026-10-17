<?php

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Kayıt sayfasında "Geçmiş": denetim izini bu kayda filtrelenmiş açar (yalnızca yönetici). */
class AuditTrailAction
{
    public static function make(): Action
    {
        return Action::make('auditTrail')
            ->label('Geçmiş')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(fn (): bool => Auth::user()?->can(Permission::AuditLogView) ?? false)
            ->url(fn (Model $record): string => AuditLogResource::getUrl('index', [
                'subject_type' => $record->getMorphClass(),
                'subject_id' => $record->getKey(),
            ]));
    }
}
