<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Stok kartı yönetimi. Bakiye burada değişmez; yalnızca StockService değiştirir.
 */
class PartService
{
    private const FIELDS = ['code', 'name', 'unit_id', 'type', 'barcode', 'critical_level'];

    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array{code: string, name: string, unit_id: int, type: mixed, barcode?: ?string, critical_level?: string|int|float|null}  $data
     */
    public function create(array $data): Part
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data): Part {
            $part = new Part(Arr::only($data, self::FIELDS));
            $part->is_active = true;
            $part->save();

            $this->stock->openBalance($part);

            return $part;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Part $part, array $data, int $expectedLockVersion): Part
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($part, $data, $expectedLockVersion): Part {
            $part->fill(Arr::only($data, self::FIELDS));

            if ($part->isDirty('unit_id')) {
                // Bakiye satırı kilitlenir: StockService hareket yazarken aynı satırı kilitlediği için
                // kontrol ile birim değişikliği arasında yeni hareket oluşamaz.
                StockBalance::query()->where('part_id', $part->getKey())->lockForUpdate()->first();

                if ($part->stockMovements()->exists()) {
                    throw new BusinessRuleException('Hareketi olan parçanın birimi değiştirilemez.');
                }
            }

            $part->saveExpectingVersion($expectedLockVersion);

            return $part;
        });
    }

    public function deactivate(Part $part): Part
    {
        $part->is_active = false;
        $part->save();

        return $part;
    }

    public function activate(Part $part): Part
    {
        $part->is_active = true;
        $part->save();

        return $part;
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('critical_level', $data)) {
            $level = Quantity::parse($data['critical_level'] ?? 0);

            if (Quantity::compare($level, '0') < 0) {
                throw new BusinessRuleException('Kritik seviye negatif olamaz.');
            }

            $data['critical_level'] = $level;
        }

        return $data;
    }
}
