<?php

use App\Http\Controllers\LabelController;
use App\Http\Controllers\MotorDesignImageController;
use App\Http\Controllers\PartImageController;
use App\Http\Controllers\ReportExportDownloadController;
use App\Http\Middleware\LogoutInactiveUser;
use Illuminate\Support\Facades\Route;

// Tüm arayüz Filament panelidir ve kök adreste (/) çalışır: app/Providers/Filament/AppPanelProvider.php
// Panel dışındaki sayfalar da oturum ister; pasifleştirilen kullanıcı burada da çıkışa zorlanır.
Route::middleware([LogoutInactiveUser::class, 'auth'])->group(function (): void {
    // Kuyrukta hazırlanan rapor dosyaları (yalnızca hazırlayan indirir).
    Route::get('/rapor-indir/{reportExport}', ReportExportDownloadController::class)->name('report-exports.download');

    // Yazdırılabilir QR etiketleri (Aşama 12).
    Route::get('/etiket/motorlar', [LabelController::class, 'motors'])->name('labels.motors');
    Route::get('/etiket/is-emri/{workOrder}', [LabelController::class, 'workOrder'])->name('labels.work-order');

    // Stok kartı görselleri.
    Route::get('/parca-gorsel/{part}', PartImageController::class)->name('parts.image');
    Route::get('/tasarim-gorsel/{motorDesign}', MotorDesignImageController::class)->name('motor-designs.image');
});
