<?php

namespace App\Filament\Pages\Reports;

use App\Reports\OrderStatusReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class OrderStatusPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'raporlar/siparis-durumu';

    protected static function reportClass(): string
    {
        return OrderStatusReport::class;
    }
}
