<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Reports\ReportRegistry;
use App\Services\ReportExportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Büyük rapor dışa aktarımı (kuyrukta). Bitince hazırlayana Filament veritabanı bildirimi gider.
 */
class ExportReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public string $exportId) {}

    public function handle(ReportExportService $exports): void
    {
        $export = ReportExport::query()->with('user')->findOrFail($this->exportId);
        $exports->build($export);

        Notification::make()
            ->success()
            ->title(ReportRegistry::find($export->report_key)->title().' hazır')
            ->body(sprintf('%s (%s satır). Dosya %d gün saklanır.', $export->file_name, number_format($export->row_count, 0, ',', '.'), ReportExportService::KEEP_DAYS))
            ->actions([
                Action::make('download')->label('İndir')->url(route('report-exports.download', $export))->markAsRead(),
            ])
            ->sendToDatabase($export->user);
    }

    public function failed(Throwable $exception): void
    {
        $export = ReportExport::query()->with('user')->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();

        Notification::make()
            ->danger()
            ->title('Rapor hazırlanamadı')
            ->body($export->file_name)
            ->sendToDatabase($export->user);
    }
}
