<?php

namespace App\Reports;

use InvalidArgumentException;

/** Tüm raporlar (anahtar => sınıf). Kuyruktaki dışa aktarım raporu anahtarla bulur. */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const REPORTS = [
        ProductionCountReport::class,
        WorkOrderPerformanceReport::class,
        OperatorProductionReport::class,
        StockBalanceReport::class,
        StockMovementListReport::class,
        OrderStatusReport::class,
    ];

    public static function find(string $key): Report
    {
        foreach (self::REPORTS as $class) {
            if ($class::key() === $key) {
                return app($class);
            }
        }

        throw new InvalidArgumentException("Bilinmeyen rapor: {$key}");
    }
}
