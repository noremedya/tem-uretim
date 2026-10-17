<?php

namespace App\Filament\Pages\Reports;

use App\Reports\ProductionCountReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ProductionCountPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'raporlar/uretim-adedi';

    protected static function reportClass(): string
    {
        return ProductionCountReport::class;
    }
}
