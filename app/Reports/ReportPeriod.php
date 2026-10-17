<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * Hazır tarih aralıkları (CLAUDE.md bölüm 8). Hafta pazartesi başlar.
 */
final class ReportPeriod
{
    public const PRESETS = [
        'today' => 'Bugün',
        'yesterday' => 'Dün',
        'this_week' => 'Bu hafta',
        'last_week' => 'Geçen hafta',
        'this_month' => 'Bu ay',
        'last_month' => 'Geçen ay',
        'custom' => 'Özel',
    ];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(string $preset, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'last_week' => [$today->subWeek()->startOfWeek(CarbonImmutable::MONDAY), $today->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };
    }

    /** Gün/hafta/ay kırılımının etiketi. */
    public static function bucketLabel(CarbonImmutable $date, string $grouping): string
    {
        return match ($grouping) {
            'week' => sprintf('%d. hafta (%s–%s)', $date->isoWeek(), $date->startOfWeek(CarbonImmutable::MONDAY)->format('d.m'), $date->endOfWeek(CarbonImmutable::SUNDAY)->format('d.m.Y')),
            'month' => $date->locale('tr')->translatedFormat('F Y'),
            default => $date->format('d.m.Y'),
        };
    }
}
