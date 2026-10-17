<?php

namespace App\Services;

use App\Jobs\ExportReportJob;
use App\Models\ReportExport;
use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportExcelWriter;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rapor dışa aktarımı (CLAUDE.md bölüm 8): eşiğe kadar doğrudan indirme, üstü kuyrukta hazırlanır ve bitince
 * bildirim gelir. Dosyalar local diskte `report-exports/` altındadır.
 */
class ReportExportService
{
    public const QUEUE_THRESHOLD = 2000;

    public const DIRECTORY = 'report-exports';

    public const KEEP_DAYS = 7;

    public function __construct(private readonly ReportExcelWriter $writer) {}

    /**
     * Küçük raporu hemen indirir (dönen yanıt), büyük raporu kuyruğa alır (null döner).
     */
    public function export(Report $report, ReportFilters $filters, User $user): ?BinaryFileResponse
    {
        $this->authorize($report, $user);

        $rows = $report->rows($filters);

        if (count($rows) <= self::QUEUE_THRESHOLD) {
            $path = tempnam(sys_get_temp_dir(), 'rapor').'.xlsx';
            $this->writer->write($report, $filters, $rows, $path);

            return response()->download($path, $report->fileName($filters))->deleteFileAfterSend();
        }

        $export = new ReportExport;
        $export->forceFill([
            'user_id' => $user->getKey(),
            'report_key' => $report::key(),
            'filters' => $filters->toArray(),
            'file_name' => $report->fileName($filters),
            'status' => 'pending',
        ])->save();

        ExportReportJob::dispatch($export->getKey());

        return null;
    }

    /** Kuyruktaki işi yürütür: dosyayı yazar, kaydı hazır işaretler. */
    public function build(ReportExport $export): void
    {
        $report = ReportRegistry::find($export->report_key);
        $filters = ReportFilters::fromArray($export->filters);
        $this->authorize($report, $export->user);

        $rows = $report->rows($filters);
        $relative = self::DIRECTORY.'/'.$export->getKey().'.xlsx';
        Storage::disk('local')->makeDirectory(self::DIRECTORY);
        $this->writer->write($report, $filters, $rows, Storage::disk('local')->path($relative));

        $export->forceFill(['path' => $relative, 'row_count' => count($rows), 'status' => 'ready'])->save();
    }

    /** 7 günden eski dışa aktarımları (dosya ve kayıt) siler. */
    public function prune(): int
    {
        $old = ReportExport::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->get();

        foreach ($old as $export) {
            if ($export->path !== null) {
                Storage::disk('local')->delete($export->path);
            }

            $export->delete();
        }

        return $old->count();
    }

    private function authorize(Report $report, ?User $user): void
    {
        abort_unless($user !== null && $user->is_active && $report->canView($user), 403);
    }
}
