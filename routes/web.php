<?php

use App\Http\Controllers\ReportExportDownloadController;
use Illuminate\Support\Facades\Route;

// Tüm arayüz Filament panelidir ve kök adreste (/) çalışır: app/Providers/Filament/AppPanelProvider.php

// Kuyrukta hazırlanan rapor dosyaları (yalnızca hazırlayan indirir).
Route::get('/rapor-indir/{reportExport}', ReportExportDownloadController::class)
    ->middleware(['auth'])
    ->name('report-exports.download');
