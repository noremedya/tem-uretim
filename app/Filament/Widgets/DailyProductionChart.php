<?php

namespace App\Filament\Widgets;

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Models\MotorUnit;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/** Son 14 günün günlük motor üretimi (yönetici). */
class DailyProductionChart extends ChartWidget
{
    public const DAYS = 14;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Son 14 gün — günlük motor üretimi';

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return Auth::user()?->can(Permission::ReportsProduction) ?? false;
    }

    /** @return array<string, int> Y-m-d => adet (boş günler 0) */
    public static function series(): array
    {
        $from = CarbonImmutable::today()->subDays(self::DAYS - 1);

        $counts = MotorUnit::query()
            ->where('status', '!=', MotorUnitStatus::Voided->value)
            ->whereDate('production_date', '>=', $from)
            ->selectRaw('production_date::date AS day, count(*) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, self::DAYS - 1))
            ->mapWithKeys(fn (int $i) => [$day = $from->addDays($i)->toDateString() => (int) ($counts[$day] ?? 0)])
            ->all();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = static::series();

        return [
            'datasets' => [['label' => 'Motor', 'data' => array_values($series)]],
            'labels' => array_map(fn (string $day) => CarbonImmutable::parse($day)->format('d.m'), array_keys($series)),
        ];
    }
}
