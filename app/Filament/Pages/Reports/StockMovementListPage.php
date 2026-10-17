<?php

namespace App\Filament\Pages\Reports;

use App\Reports\StockMovementListReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class StockMovementListPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'raporlar/stok-hareketleri';

    protected static function reportClass(): string
    {
        return StockMovementListReport::class;
    }
}
