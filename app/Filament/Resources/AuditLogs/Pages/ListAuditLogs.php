<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Support\AuditPresenter;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Kayıt sayfalarındaki "Geçmiş" butonu ?subject_type=...&subject_id=... ile tek kaydın izini açar.
 */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    #[Url(as: 'subject_type')]
    public ?string $subjectType = null;

    #[Url(as: 'subject_id')]
    public ?int $subjectId = null;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->when($this->subjectType !== null && $this->subjectId !== null, fn (Builder $query) => $query
                ->where('subject_type', $this->subjectType)
                ->where('subject_id', $this->subjectId));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->subjectType !== null && $this->subjectId !== null
            ? AuditPresenter::subjectType($this->subjectType).' #'.$this->subjectId.' kaydının geçmişi'
            : null;
    }
}
