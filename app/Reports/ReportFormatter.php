<?php

namespace App\Reports;

use App\Support\Quantity;
use Carbon\CarbonImmutable;

/** Ekranda gösterim (Türkçe biçim). Excel'de sayılar sayı hücresidir (ReportExcelWriter). */
final class ReportFormatter
{
    public static function display(ReportColumn $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($column->type) {
            'integer' => number_format((int) $value, 0, ',', '.'),
            'decimal' => Quantity::format((string) $value),
            'hours' => number_format((float) $value, 1, ',', '.'),
            'percent' => '%'.number_format((float) $value, 1, ',', '.'),
            'date' => CarbonImmutable::parse($value)->format('d.m.Y'),
            'datetime' => CarbonImmutable::parse($value)->format('d.m.Y H:i'),
            'boolean' => $value ? 'Evet' : 'Hayır',
            default => (string) $value,
        };
    }
}
