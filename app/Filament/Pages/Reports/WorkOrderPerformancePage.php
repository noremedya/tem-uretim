<?php

namespace App\Filament\Pages\Reports;

use App\Reports\WorkOrderPerformanceReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class WorkOrderPerformancePage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'raporlar/is-emri-performansi';

    protected static function reportClass(): string
    {
        return WorkOrderPerformanceReport::class;
    }
}
