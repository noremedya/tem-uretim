<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kuyrukta hazırlanan rapor dosyası: yalnızca hazırlayan, aktif kullanıcı indirir. */
class ReportExportDownloadController
{
    public function __invoke(Request $request, ReportExport $reportExport): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $user->is_active && $reportExport->user_id === $user->getKey(), 403);
        abort_unless($reportExport->status === 'ready' && $reportExport->path !== null && Storage::disk('local')->exists($reportExport->path), 404, 'Dosya bulunamadı veya süresi doldu.');

        return Storage::disk('local')->download($reportExport->path, $reportExport->file_name);
    }
}
