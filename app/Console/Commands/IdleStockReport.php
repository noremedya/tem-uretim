<?php

namespace App\Console\Commands;

use App\Models\Part;
use App\Services\StockAlertService;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Hareketsiz stok özeti: aktif, bakiyesi > 0 olan ve config('stock.idle_days') gündür hareket görmeyen
 * parçalar yönetici ve depoya tek bir bildirimle gönderilir. Hareketsiz parça yoksa bildirim gitmez.
 */
class IdleStockReport extends Command
{
    protected $signature = 'stock:idle-report';

    protected $description = 'Uzun süredir hareket görmeyen stokları yönetici ve depoya bildirir';

    public function handle(StockAlertService $alerts): int
    {
        $days = (int) config('stock.idle_days');

        $parts = Part::query()
            ->where('is_active', true)
            ->idle($days)
            ->with(['unit', 'stockBalance'])
            ->withMax('stockMovements as last_movement_at', 'created_at')
            ->orderBy('code')
            ->get();

        if ($parts->isEmpty()) {
            $this->info("{$days} gündür hareketsiz parça yok.");

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d parça %d gündür hareket görmedi:', $parts->count(), $days));
        $this->table(
            ['Kod', 'Ad', 'Bakiye', 'Son hareket'],
            $parts->map(fn (Part $part): array => [
                $part->code,
                $part->name,
                Quantity::format((string) $part->stockBalance->quantity, $part->unit->name),
                Carbon::parse($part->last_movement_at)->format('d.m.Y'),
            ])->all(),
        );

        $alerts->idleStock($parts, $days);

        return self::SUCCESS;
    }
}
