<?php

namespace App\Filament\Pages\Reports;

use App\Reports\OperatorProductionReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class OperatorProductionPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'raporlar/operator-uretimi';

    protected static function reportClass(): string
    {
        return OperatorProductionReport::class;
    }
}
