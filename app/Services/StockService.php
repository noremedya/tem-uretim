<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stok bakiyesini değiştiren tek yer (CLAUDE.md bölüm 3 kural 2).
 *
 * Her işlem tek transaction içindedir; bakiye satırı lockForUpdate ile kilitlenir, hareket kaydı ve
 * yeni bakiye birlikte yazılır. Birden fazla parça kilitlenirken sıra her zaman part_id'ye göredir
 * (deadlock önlemi).
 *
 * Çift gönderim koruması: idempotency_key verilen işlem, aynı anahtarla daha önce kaydedilmişse yeni
 * hareket oluşturmaz, mevcut hareketi döndürür ($movement->wasRecentlyCreated === false).
 */
class StockService
{
    public function __construct(private readonly StockAlertService $alerts) {}

    /** Yeni parça için 0 bakiyeli satırı açar. Parça oluşturma transaction'ı içinde çağrılır. */
    public function openBalance(Part $part): StockBalance
    {
        $balance = new StockBalance;
        $balance->forceFill(['part_id' => $part->getKey(), 'quantity' => '0', 'is_below_critical' => false])->save();

        return $balance;
    }

    public function stockIn(Part $part, string|int|float $quantity, User $user, ?string $description = null, ?string $idempotencyKey = null): StockMovement
    {
        $quantity = $this->positive($quantity);

        return $this->record($part, StockMovementType::StockIn, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /** Çıkış (tedarikçiye iade dahil). Açıklama zorunlu. */
    public function stockOut(Part $part, string|int|float $quantity, User $user, ?string $description, ?string $idempotencyKey = null): StockMovement
    {
        $quantity = Quantity::negate($this->positive($quantity));

        return $this->record($part, StockMovementType::StockOut, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /** Üretimden/müşteriden depoya dönüş. */
    public function returnToStock(Part $part, string|int|float $quantity, User $user, ?string $description = null, ?string $idempotencyKey = null): StockMovement
    {
        $quantity = $this->positive($quantity);

        return $this->record($part, StockMovementType::Return, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /**
     * Sayım: sayılan miktar girilir, fark hareket olarak yazılır. Fark 0 ise hareket oluşmaz ve null döner.
     * Pasif parçada yapılabilen tek işlemdir.
     */
    public function adjustCount(Part $part, string|int|float $countedQuantity, User $user, ?string $description, ?string $idempotencyKey = null): ?StockMovement
    {
        $counted = Quantity::parse($countedQuantity);

        if (Quantity::compare($counted, '0') < 0) {
            throw new BusinessRuleException('Sayılan miktar negatif olamaz.');
        }

        return $this->record(
            $part,
            StockMovementType::CountAdjustment,
            function (Part $part, string $balance) use ($counted): ?string {
                $this->ensureUnitPrecision($part, $counted);

                $difference = Quantity::sub($counted, $balance);

                return Quantity::isZero($difference) ? null : $difference;
            },
            $user,
            $description,
            $idempotencyKey,
        );
    }

    /**
     * Ters kayıt: orijinal hareketin tam tersi miktarda correction hareketi. Bir hareket yalnızca bir kez
     * ters kaydedilebilir; ters kaydın kendisi ters kaydedilemez; stoğu eksiye düşürecekse reddedilir.
     */
    public function correct(StockMovement $movement, User $user, ?string $description, ?string $idempotencyKey = null): StockMovement
    {
        if ($movement->type === StockMovementType::Correction) {
            throw new BusinessRuleException('Ters kaydın kendisi ters kaydedilemez.');
        }

        if (! $movement->isCorrectableType()) {
            throw new BusinessRuleException('Üretim sarfı hareketleri elle ters kaydedilemez.');
        }

        $quantity = Quantity::negate((string) $movement->quantity);

        return $this->record(
            Part::query()->findOrFail($movement->part_id),
            StockMovementType::Correction,
            function () use ($movement, $quantity): string {
                // Bakiye satırı kilitliyken kontrol edilir: aynı parçadaki ters kayıtlar sıraya girer.
                if (StockMovement::query()->where('corrected_movement_id', $movement->getKey())->exists()) {
                    throw new BusinessRuleException('Bu hareket zaten ters kaydedilmiş.');
                }

                return $quantity;
            },
            $user,
            $description,
            $idempotencyKey,
            correctedMovement: $movement,
        );
    }

    /**
     * Üretim sarfı: birden fazla parça tek transaction'da düşülür. Herhangi biri yetersizse hiçbir şey
     * değişmez ve tüm eksikler InsufficientStockException ile listelenir.
     *
     * @param  iterable<array{part: Part|int, quantity: string|int|float}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function consume(iterable $lines, Model $reference, User $user, ?string $description = null): Collection
    {
        // Aynı parça birden fazla satırda olabilir: toplanır.
        $required = [];
        foreach ($lines as $line) {
            $partId = $line['part'] instanceof Part ? $line['part']->getKey() : (int) $line['part'];
            $required[$partId] = Quantity::add($required[$partId] ?? '0', $this->positive($line['quantity']));
        }

        if ($required === []) {
            return collect();
        }

        return DB::transaction(function () use ($required, $reference, $user, $description): Collection {
            $balances = $this->lockBalances(array_keys($required));
            $parts = Part::query()->with('unit')->findMany(array_keys($required))->keyBy('id');

            $shortages = [];
            foreach ($required as $partId => $quantity) {
                $part = $parts[$partId];
                $this->ensureTypeAllowed($part, StockMovementType::ProductionConsumption);
                $this->ensureUnitPrecision($part, $quantity);

                $available = (string) $balances[$partId]->quantity;
                if (Quantity::compare($available, $quantity) < 0) {
                    $shortages[] = $this->shortage($part, $quantity, $available);
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            return collect($required)->map(fn (string $quantity, int $partId) => $this->apply(
                $parts[$partId],
                $balances[$partId],
                StockMovementType::ProductionConsumption,
                Quantity::negate($quantity),
                $user,
                $description,
                reference: $reference,
            ))->values();
        });
    }

    /**
     * @param  Closure(Part, string): ?string  $resolveDelta  Kilitli bakiyeye göre işaretli miktar; null ise hareket yok.
     */
    private function record(
        Part $part,
        StockMovementType $type,
        Closure $resolveDelta,
        User $user,
        ?string $description,
        ?string $idempotencyKey,
        ?StockMovement $correctedMovement = null,
    ): ?StockMovement {
        $description = filled($description) ? trim($description) : null;

        if ($type->requiresDescription() && $description === null) {
            throw new BusinessRuleException("{$type->label()} işleminde açıklama zorunludur.");
        }

        try {
            return DB::transaction(function () use ($part, $type, $resolveDelta, $user, $description, $idempotencyKey, $correctedMovement): ?StockMovement {
                $balance = $this->lockBalances([$part->getKey()])[$part->getKey()];

                // Kilit alındıktan sonra bakılır: aynı anahtarlı eşzamanlı istek, ilki commit edince bunu görür.
                if ($idempotencyKey !== null && ($existing = StockMovement::query()->firstWhere('idempotency_key', $idempotencyKey))) {
                    return $existing;
                }

                // Parçanın güncel hâli (pasifleştirme, birim değişikliği) kilit altında okunur.
                $part = Part::query()->with('unit')->findOrFail($part->getKey());

                $this->ensureTypeAllowed($part, $type);

                $delta = $resolveDelta($part, (string) $balance->quantity);

                if ($delta === null) {
                    return null;
                }

                $this->ensureUnitPrecision($part, $delta);

                return $this->apply($part, $balance, $type, $delta, $user, $description, $idempotencyKey, $correctedMovement);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Yedek koruma: kontrolü aşan eşzamanlı istekleri veritabanındaki unique index yakalar.
            if ($idempotencyKey !== null && ($existing = StockMovement::query()->firstWhere('idempotency_key', $idempotencyKey))) {
                return $existing;
            }

            if ($correctedMovement !== null) {
                throw new BusinessRuleException('Bu hareket zaten ters kaydedilmiş.');
            }

            throw $exception;
        }
    }

    /**
     * Kilitli bakiyeye hareketi uygular. Çağıran transaction içinde ve bakiye satırı kilitli olmalıdır.
     */
    private function apply(
        Part $part,
        StockBalance $balance,
        StockMovementType $type,
        string $delta,
        User $user,
        ?string $description,
        ?string $idempotencyKey = null,
        ?StockMovement $correctedMovement = null,
        ?Model $reference = null,
    ): StockMovement {
        $before = (string) $balance->quantity;
        $after = Quantity::add($before, $delta);

        if (Quantity::compare($after, '0') < 0) {
            throw new InsufficientStockException([$this->shortage($part, Quantity::negate($delta), $before)]);
        }

        $movement = new StockMovement;
        $movement->forceFill([
            'part_id' => $part->getKey(),
            'type' => $type,
            'quantity' => $delta,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'corrected_movement_id' => $correctedMovement?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
            'user_id' => $user->getKey(),
        ])->save();

        $balance->quantity = $after;
        $this->syncCriticalState($part, $balance);
        $balance->save();

        return $movement;
    }

    /**
     * Kritik stok bildirimi yalnızca kritik seviyeye ilk inişte gönderilir; bakiye tekrar kritik seviyenin
     * üstüne çıkana kadar aynı parça için tekrar gönderilmez. Bildirim commit sonrası gider (geri alınan
     * işlem bildirim üretmez).
     */
    private function syncCriticalState(Part $part, StockBalance $balance): void
    {
        $isCritical = $part->isCriticalAt((string) $balance->quantity);

        if ($isCritical && ! $balance->is_below_critical) {
            $balance->is_below_critical = true;
            $quantity = (string) $balance->quantity;

            DB::afterCommit(fn () => $this->alerts->criticalStock($part, $quantity));
        } elseif (! $isCritical && $balance->is_below_critical) {
            $balance->is_below_critical = false;
        }
    }

    /**
     * @param  list<int>  $partIds
     * @return Collection<int, StockBalance> part_id => bakiye
     */
    private function lockBalances(array $partIds): Collection
    {
        sort($partIds);

        $balances = StockBalance::query()
            ->whereIn('part_id', $partIds)
            ->orderBy('part_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('part_id');

        if ($balances->count() !== count($partIds)) {
            throw new BusinessRuleException('Parçanın stok bakiyesi bulunamadı.');
        }

        return $balances;
    }

    private function ensureTypeAllowed(Part $part, StockMovementType $type): void
    {
        if (! $part->is_active && ! $type->allowedOnInactivePart()) {
            throw new BusinessRuleException("{$part->code} {$part->name} pasif. Pasif parçaya yalnızca sayım yapılabilir.");
        }
    }

    private function ensureUnitPrecision(Part $part, string $quantity): void
    {
        if (! $part->unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("{$part->code} {$part->name} için miktar tam sayı olmalıdır (birim: {$part->unit->name}).");
        }
    }

    private function positive(string|int|float $quantity): string
    {
        $quantity = Quantity::parse($quantity);

        if (Quantity::compare($quantity, '0') <= 0) {
            throw new BusinessRuleException('Miktar sıfırdan büyük olmalıdır.');
        }

        return $quantity;
    }

    /**
     * @return array{part_id: int, code: string, name: string, unit: string, required: string, available: string, missing: string}
     */
    private function shortage(Part $part, string $required, string $available): array
    {
        return [
            'part_id' => $part->getKey(),
            'code' => $part->code,
            'name' => $part->name,
            'unit' => $part->unit->name,
            'required' => $required,
            'available' => $available,
            'missing' => Quantity::sub($required, $available),
        ];
    }
}
