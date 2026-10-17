<?php

namespace App\Console\Commands;

use App\Services\ReportExportService;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Kuyrukta hazırlanmış 7 günden eski rapor dosyalarını siler';

    public function handle(ReportExportService $exports): int
    {
        $this->info(sprintf('%d eski rapor dosyası silindi.', $exports->prune()));

        return self::SUCCESS;
    }
}
