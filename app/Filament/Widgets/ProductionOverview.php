<?php

namespace App\Filament\Widgets;

use App\Enums\MotorUnitStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\MotorUnit;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** Üretim kartları (yönetici, depo): bugün/bu hafta tamamlanan motor, üretimdeki ve geciken iş emirleri. */
class ProductionOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // Yalnızca operatör rolündeki kullanıcının ana ekranı sade kalır (CLAUDE.md bölüm 6).
        $user = Auth::user();

        return $user !== null && $user->can(Permission::WorkOrdersViewAll) && ! $user->hasExactRoles([Role::Operator->value]);
    }

    /** @return array{today: int, week: int, in_progress: int, overdue: int} */
    public static function counts(): array
    {
        $motors = fn (CarbonImmutable $from) => MotorUnit::query()
            ->where('status', '!=', MotorUnitStatus::Voided->value)
            ->whereDate('production_date', '>=', $from)
            ->count();

        return [
            'today' => $motors(CarbonImmutable::today()),
            'week' => $motors(CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)),
            'in_progress' => WorkOrder::query()->where('status', WorkOrderStatus::InProgress->value)->count(),
            'overdue' => WorkOrder::query()->overdue()->count(),
        ];
    }

    protected function getStats(): array
    {
        $counts = static::counts();
        $list = WorkOrderResource::getUrl('index');

        return [
            Stat::make('Bugün tamamlanan motor', $counts['today'])->icon('heroicon-o-check-circle'),
            Stat::make('Bu hafta tamamlanan motor', $counts['week'])->icon('heroicon-o-calendar-days'),
            Stat::make('Üretimdeki iş emri', $counts['in_progress'])->icon('heroicon-o-wrench-screwdriver')
                ->url($list.'?'.http_build_query(['filters' => ['status' => ['values' => [WorkOrderStatus::InProgress->value]]]])),
            Stat::make('Geciken iş emri', $counts['overdue'])->icon('heroicon-o-exclamation-triangle')
                ->color($counts['overdue'] > 0 ? 'danger' : 'success')
                ->url($list.'?'.http_build_query(['filters' => ['overdue' => ['isActive' => true]]])),
        ];
    }
}
