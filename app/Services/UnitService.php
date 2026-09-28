<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\Unit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UnitService
{
    public function create(array $data): Unit
    {
        return Unit::create(Arr::only($data, ['name', 'allows_decimal']));
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Unit $unit, array $data, int $expectedLockVersion): Unit
    {
        return DB::transaction(function () use ($unit, $data, $expectedLockVersion): Unit {
            $unit->fill(Arr::only($data, ['name', 'allows_decimal']));

            if ($unit->isDirty('allows_decimal') && ! $unit->allows_decimal) {
                // Birim satırı kilitlenir; stok hareketi trigger'ı aynı satırı FOR SHARE ile okur.
                Unit::query()->whereKey($unit->getKey())->lockForUpdate()->first();

                $this->ensureNoFractionalQuantities($unit);
            }

            $unit->saveExpectingVersion($expectedLockVersion);

            return $unit;
        });
    }

    /** Küsurat kapatılırken bu birimdeki parçaların bakiyesi ve kritik seviyesi tam sayı olmalı. */
    private function ensureNoFractionalQuantities(Unit $unit): void
    {
        $fractionalBalances = StockBalance::query()
            ->whereIn('part_id', Part::query()->select('id')->where('unit_id', $unit->getKey()))
            ->whereRaw('quantity <> trunc(quantity)')
            ->exists();

        $fractionalLevels = Part::query()
            ->where('unit_id', $unit->getKey())
            ->whereRaw('critical_level <> trunc(critical_level)')
            ->exists();

        if ($fractionalBalances || $fractionalLevels) {
            throw new BusinessRuleException('Bu birimde küsuratlı bakiyesi veya kritik seviyesi olan parçalar var; küsurat kapatılamaz.');
        }
    }
}
