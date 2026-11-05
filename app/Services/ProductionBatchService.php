<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductionBatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Üretim partileri. Parti elle açılmaz: her iş emri açılırken kendi partisi otomatik oluşur (WorkOrderService);
 * sonradan yalnızca notu değişir.
 */
class ProductionBatchService
{
    private const FIELDS = ['notes'];

    /** İş emriyle birlikte, aynı transaction içinde çağrılır. */
    public function createAutomatic(string $batchNumber): ProductionBatch
    {
        $batch = new ProductionBatch(['batch_number' => $batchNumber, 'date' => today()]);
        $this->ensureValid($batch);
        $batch->save();

        return $batch;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(ProductionBatch $batch, array $data, int $expectedLockVersion): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $data, $expectedLockVersion): ProductionBatch {
            $batch->fill(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($batch);
            $batch->saveExpectingVersion($expectedLockVersion);

            return $batch;
        });
    }

    private function ensureValid(ProductionBatch $batch): void
    {
        if (blank($batch->batch_number)) {
            throw new BusinessRuleException('Parti numarası zorunludur.');
        }

        if ($batch->date === null) {
            throw new BusinessRuleException('Parti tarihi zorunludur.');
        }

        if (ProductionBatch::query()->where('batch_number', $batch->batch_number)->when($batch->exists, fn ($q) => $q->whereKeyNot($batch->getKey()))->exists()) {
            throw new BusinessRuleException("{$batch->batch_number} parti numarası zaten kullanılıyor.");
        }
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('notes', $data)) {
            $data['notes'] = filled($data['notes']) ? trim($data['notes']) : null;
        }

        return $data;
    }
}
