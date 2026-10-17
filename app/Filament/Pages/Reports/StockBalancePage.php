<?php

namespace App\Filament\Pages\Reports;

use App\Reports\StockBalanceReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockBalancePage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'raporlar/stok-bakiyeleri';

    protected static function reportClass(): string
    {
        return StockBalanceReport::class;
    }
}
