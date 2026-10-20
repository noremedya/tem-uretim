<?php

namespace App\Services;

use App\Enums\PartType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Quantity;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stok bakiyesini değiştiren tek yer (CLAUDE.md bölüm 3 kural 2).
 *
 * Bakiye ürün × depo bazlıdır. Depo verilmeyen işlem ürünün varsayılan deposunda yapılır (WarehouseService).
 * Her işlem tek transaction içindedir; bakiye satırları lockForUpdate ile kilitlenir, hareket kaydı ve yeni
 * bakiye birlikte yazılır. Birden fazla satır kilitlenirken sıra her zaman (part_id, warehouse_id)'ye göredir
 * (deadlock önlemi). Hiç hareket görmemiş depo için bakiye satırı ilk harekette 0 ile açılır.
 *
 * Eksi bakiye: yalnızca eksiye düşmeye izin veren depoda ve mamul dışındaki üründe (veritabanında da).
 *
 * Çift gönderim koruması: idempotency_key verilen işlem, aynı anahtarla daha önce kaydedilmişse yeni
 * hareket oluşturmaz, mevcut hareketi döndürür ($movement->wasRecentlyCreated === false).
 */
class StockService
{
    public function __construct(
        private readonly StockAlertService $alerts,
        private readonly WarehouseService $warehouses,
    ) {}

    /**
     * Yeni ürün için varsayılan deposunda 0 bakiyeli satırı (kritik seviyesiyle) açar. Ürün oluşturma
     * transaction'ı içinde çağrılır.
     */
    public function openBalance(Part $part, string|int|float $criticalLevel = '0'): StockBalance
    {
        $balance = new StockBalance;
        $balance->forceFill([
            'part_id' => $part->getKey(),
            'warehouse_id' => $this->warehouses->defaultFor($part)->getKey(),
            'quantity' => '0',
            'critical_level' => $this->criticalLevelValue($part, $criticalLevel),
            'is_below_critical' => false,
        ])->save();

        return $balance;
    }

    /**
     * Ürünün bir depodaki (varsayılan: varsayılan deposu) kritik seviyesini değiştirir; denetim izine yazılır.
     * Bildirim durumu bir sonraki harekette güncellenir (seviye değişikliği tek başına bildirim göndermez).
     */
    public function setCriticalLevel(Part $part, string|int|float|null $level, ?Warehouse $warehouse = null): StockBalance
    {
        $level = $this->criticalLevelValue($part, $level ?? '0');

        return DB::transaction(function () use ($part, $level, $warehouse): StockBalance {
            $warehouse ??= $this->warehouses->defaultFor($part);
            $balance = $this->lockBalances([[$part->getKey(), $warehouse->getKey()]])->first();
            $old = (string) $balance->critical_level;

            if (Quantity::compare($old, $level) === 0) {
                return $balance;
            }

            $balance->critical_level = $level;
            if (! $balance->isCriticalAt()) {
                $balance->is_below_critical = false;
            }
            $balance->save();

            // Kritik seviye bakiye satırında tutulur (model olayı yok); ürünün denetim izine elle yazılır.
            activity()
                ->performedOn($part)
                ->event('updated')
                ->withChanges(['attributes' => ['critical_level' => $level, 'warehouse_id' => $warehouse->getKey()], 'old' => ['critical_level' => $old]])
                ->log('updated');

            return $balance;
        });
    }

    public function stockIn(Part $part, string|int|float $quantity, User $user, ?string $description = null, ?string $idempotencyKey = null, ?Warehouse $warehouse = null): StockMovement
    {
        $quantity = $this->positive($quantity);

        return $this->record($part, $warehouse, StockMovementType::StockIn, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /** Çıkış (tedarikçiye iade dahil). Açıklama zorunlu. */
    public function stockOut(Part $part, string|int|float $quantity, User $user, ?string $description, ?string $idempotencyKey = null, ?Warehouse $warehouse = null): StockMovement
    {
        $quantity = Quantity::negate($this->positive($quantity));

        return $this->record($part, $warehouse, StockMovementType::StockOut, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /** Üretimden/müşteriden depoya dönüş. */
    public function returnToStock(Part $part, string|int|float $quantity, User $user, ?string $description = null, ?string $idempotencyKey = null, ?Warehouse $warehouse = null): StockMovement
    {
        $quantity = $this->positive($quantity);

        return $this->record($part, $warehouse, StockMovementType::Return, fn () => $quantity, $user, $description, $idempotencyKey);
    }

    /**
     * Sayım (depo bazlı): sayılan miktar girilir, fark hareket olarak yazılır. Fark 0 ise hareket oluşmaz ve
     * null döner. Pasif ürüne ve pasif depoda yapılabilir.
     */
    public function adjustCount(Part $part, string|int|float $countedQuantity, User $user, ?string $description, ?string $idempotencyKey = null, ?Warehouse $warehouse = null): ?StockMovement
    {
        $counted = Quantity::parse($countedQuantity);

        if (Quantity::compare($counted, '0') < 0) {
            throw new BusinessRuleException('Sayılan miktar negatif olamaz.');
        }

        return $this->record(
            $part,
            $warehouse,
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
     * Ters kayıt: orijinal hareketin deposunda, tam tersi miktarda correction hareketi. Bir hareket yalnızca bir
     * kez ters kaydedilebilir; ters kaydın kendisi ve transfer ters kaydedilemez; stoğu (izin verilmeyen depoda)
     * eksiye düşürecekse reddedilir.
     */
    public function correct(StockMovement $movement, User $user, ?string $description, ?string $idempotencyKey = null): StockMovement
    {
        if ($movement->type === StockMovementType::Correction) {
            throw new BusinessRuleException('Ters kaydın kendisi ters kaydedilemez.');
        }

        if ($movement->type === StockMovementType::Transfer) {
            throw new BusinessRuleException('Transfer ters kaydedilemez; ters yönde yeni bir transfer yapın.');
        }

        if (! $movement->isCorrectableType()) {
            throw new BusinessRuleException("{$movement->type->label()} hareketleri elle ters kaydedilemez; iş emri geri alma akışını kullanın.");
        }

        $quantity = Quantity::negate((string) $movement->quantity);

        return $this->record(
            Part::query()->findOrFail($movement->part_id),
            Warehouse::query()->findOrFail($movement->warehouse_id),
            StockMovementType::Correction,
            function () use ($movement, $quantity): string {
                // Bakiye satırı kilitliyken kontrol edilir: aynı satırdaki ters kayıtlar sıraya girer.
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
     * Depolar arası transfer: kaynakta − ve hedefte + iki transfer hareketi (aynı transfer_id). Çift gönderim
     * anahtarı çıkış bacağında tutulur; aynı anahtarla ikinci istek ilk transferin çıkış hareketini döndürür.
     * Mamul transferi motor seçimiyle yapılır (Aşama B2); burada reddedilir.
     *
     * @return EloquentCollection<int, StockMovement> [çıkış, giriş]
     */
    public function transfer(Part $part, Warehouse $from, Warehouse $to, string|int|float $quantity, User $user, ?string $description = null, ?string $idempotencyKey = null): EloquentCollection
    {
        $quantity = $this->positive($quantity);
        $description = filled($description) ? trim($description) : null;

        if ($from->is($to)) {
            throw new BusinessRuleException('Kaynak ve hedef depo aynı olamaz.');
        }

        try {
            return DB::transaction(function () use ($part, $from, $to, $quantity, $user, $description, $idempotencyKey): EloquentCollection {
                $balances = $this->lockBalances([[$part->getKey(), $from->getKey()], [$part->getKey(), $to->getKey()]]);

                if ($idempotencyKey !== null && ($existing = StockMovement::query()->firstWhere('idempotency_key', $idempotencyKey))) {
                    return StockMovement::query()->where('transfer_id', $existing->transfer_id)->orderBy('id')->get();
                }

                $part = Part::query()->with('unit')->findOrFail($part->getKey());
                $from = Warehouse::query()->findOrFail($from->getKey());
                $to = Warehouse::query()->findOrFail($to->getKey());

                $this->ensureTypeAllowed($part, StockMovementType::Transfer);
                $this->ensureUnitPrecision($part, $quantity);
                $this->ensureWarehouseAllowed($from, StockMovementType::Transfer, outgoing: true);
                $this->ensureWarehouseAllowed($to, StockMovementType::Transfer, outgoing: false);

                $transferId = (string) Str::uuid();

                return new EloquentCollection([
                    $this->apply($part, $from, $balances[$this->key($part->getKey(), $from->getKey())], StockMovementType::Transfer, Quantity::negate($quantity), $user, $description, $idempotencyKey, transferId: $transferId),
                    $this->apply($part, $to, $balances[$this->key($part->getKey(), $to->getKey())], StockMovementType::Transfer, $quantity, $user, $description, transferId: $transferId),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null && ($existing = StockMovement::query()->firstWhere('idempotency_key', $idempotencyKey))) {
                return StockMovement::query()->where('transfer_id', $existing->transfer_id)->orderBy('id')->get();
            }

            throw $exception;
        }
    }

    /**
     * Üretim sarfı: birden fazla ürün tek transaction'da (her biri varsayılan deposundan) düşülür. Herhangi biri
     * yetersizse hiçbir şey değişmez ve tüm eksikler InsufficientStockException ile listelenir.
     *
     * @param  iterable<array{part: Part|int, quantity: string|int|float}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function consume(iterable $lines, Model $reference, User $user, ?string $description = null): Collection
    {
        // Aynı ürün birden fazla satırda olabilir: toplanır.
        $required = [];
        foreach ($lines as $line) {
            $partId = $line['part'] instanceof Part ? $line['part']->getKey() : (int) $line['part'];
            $required[$partId] = Quantity::add($required[$partId] ?? '0', $this->positive($line['quantity']));
        }

        if ($required === []) {
            return collect();
        }

        return DB::transaction(function () use ($required, $reference, $user, $description): Collection {
            $parts = Part::query()->with('unit')->findMany(array_keys($required))->keyBy('id');
            $warehouseIds = $parts->map(fn (Part $part) => $this->warehouses->defaultFor($part)->getKey());
            $balances = $this->lockBalances($parts->map(fn (Part $part) => [$part->getKey(), $warehouseIds[$part->getKey()]])->values()->all());
            $parts = Part::query()->with('unit')->findMany(array_keys($required))->keyBy('id');
            $warehouses = Warehouse::query()->findMany($warehouseIds->unique()->values())->keyBy('id');

            $shortages = [];
            foreach ($required as $partId => $quantity) {
                $part = $parts[$partId];
                $warehouse = $warehouses[$warehouseIds[$partId]];
                $this->ensureTypeAllowed($part, StockMovementType::ProductionConsumption);
                $this->ensureWarehouseAllowed($warehouse, StockMovementType::ProductionConsumption);
                $this->ensureUnitPrecision($part, $quantity);

                $available = (string) $balances[$this->key($partId, $warehouse->getKey())]->quantity;
                if (! $warehouse->allowsNegativeFor($part) && Quantity::compare($available, $quantity) < 0) {
                    $shortages[] = $this->shortage($part, $quantity, $available, $warehouse);
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            return collect($required)->map(function (string $quantity, int $partId) use ($parts, $warehouses, $warehouseIds, $balances, $user, $description, $reference): StockMovement {
                $warehouse = $warehouses[$warehouseIds[$partId]];

                return $this->apply(
                    $parts[$partId],
                    $warehouse,
                    $balances[$this->key($partId, $warehouse->getKey())],
                    StockMovementType::ProductionConsumption,
                    Quantity::negate($quantity),
                    $user,
                    $description,
                    reference: $reference,
                );
            })->values();
        });
    }

    /**
     * İş emri tamamlama: net sarf (production_consumption), fire (production_scrap) ve üretilen kartın girişi
     * (production_output) tek transaction'da; her ürün kendi varsayılan deposunda. Tüm bakiyeler sırayla
     * kilitlenir. Herhangi bir malzeme (fire dahil) yetersizse hiçbir şey değişmez ve tüm eksikler
     * InsufficientStockException ile listelenir.
     *
     * @param  list<array{part_id: int, consumption: string, scrap: string}>  $materials
     * @return Collection<int, StockMovement>
     */
    public function recordProduction(array $materials, Part $output, string|int $outputQuantity, Model $reference, User $user, ?string $description = null): Collection
    {
        $outputQuantity = $this->positive($outputQuantity);

        return DB::transaction(function () use ($materials, $output, $outputQuantity, $reference, $user, $description): Collection {
            $partIds = array_values(array_unique([...array_column($materials, 'part_id'), $output->getKey()]));
            $parts = Part::query()->with('unit')->findMany($partIds)->keyBy('id');
            $warehouseIds = $parts->map(fn (Part $part) => $this->warehouses->defaultFor($part)->getKey());
            $balances = $this->lockBalances($parts->map(fn (Part $part) => [$part->getKey(), $warehouseIds[$part->getKey()]])->values()->all());
            $parts = Part::query()->with('unit')->findMany($partIds)->keyBy('id');
            $warehouses = Warehouse::query()->findMany($warehouseIds->unique()->values())->keyBy('id');
            $balanceOf = fn (Part $part): StockBalance => $balances[$this->key($part->getKey(), $warehouseIds[$part->getKey()])];
            $warehouseOf = fn (Part $part): Warehouse => $warehouses[$warehouseIds[$part->getKey()]];

            // Aynı ürün birden fazla satırda olabilir: ihtiyaç toplanarak kontrol edilir.
            $needed = [];
            foreach ($materials as $line) {
                $part = $parts[$line['part_id']];
                $this->ensureTypeAllowed($part, StockMovementType::ProductionConsumption);
                $this->ensureWarehouseAllowed($warehouseOf($part), StockMovementType::ProductionConsumption);
                $this->ensureUnitPrecision($part, $line['consumption']);
                $this->ensureUnitPrecision($part, $line['scrap']);

                $needed[$part->getKey()] = Quantity::add($needed[$part->getKey()] ?? '0', Quantity::add($line['consumption'], $line['scrap']));
            }

            $shortages = [];
            foreach ($needed as $partId => $quantity) {
                $part = $parts[$partId];
                $available = (string) $balanceOf($part)->quantity;

                if (! $warehouseOf($part)->allowsNegativeFor($part) && Quantity::compare($available, $quantity) < 0) {
                    $shortages[] = $this->shortage($part, $quantity, $available, $warehouseOf($part));
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            $product = $parts[$output->getKey()];
            $this->ensureTypeAllowed($product, StockMovementType::ProductionOutput);
            $this->ensureWarehouseAllowed($warehouseOf($product), StockMovementType::ProductionOutput);
            $this->ensureUnitPrecision($product, $outputQuantity);

            $movements = collect();
            foreach ($materials as $line) {
                $part = $parts[$line['part_id']];

                if (Quantity::compare($line['consumption'], '0') > 0) {
                    $movements->push($this->apply($part, $warehouseOf($part), $balanceOf($part), StockMovementType::ProductionConsumption, Quantity::negate($line['consumption']), $user, $description, reference: $reference));
                }

                if (Quantity::compare($line['scrap'], '0') > 0) {
                    $movements->push($this->apply($part, $warehouseOf($part), $balanceOf($part), StockMovementType::ProductionScrap, Quantity::negate($line['scrap']), $user, $description, reference: $reference));
                }
            }

            $movements->push($this->apply($product, $warehouseOf($product), $balanceOf($product), StockMovementType::ProductionOutput, $outputQuantity, $user, $description, reference: $reference));

            return $movements;
        });
    }

    /**
     * İş emri geri alma: tamamlamanın sarf, fire ve üretimden giriş hareketlerini kendi depolarında ters kaydeder
     * (correction). Elle ters kayıt bu tipler için kapalıdır; yalnızca ProductionService::reverse çağırır.
     * Ters kayıt stoğu (izin verilmeyen depoda) eksiye düşürecekse (ör. üretilen yarı mamul kullanılmışsa)
     * hiçbir şey değişmez ve InsufficientStockException fırlar.
     *
     * @param  Collection<int, StockMovement>  $movements
     * @return Collection<int, StockMovement>
     */
    public function reverseProduction(Collection $movements, Model $reference, User $user, string $description): Collection
    {
        return DB::transaction(function () use ($movements, $reference, $user, $description): Collection {
            $balances = $this->lockBalances($movements->map(fn (StockMovement $m) => [$m->part_id, $m->warehouse_id])->values()->all());
            $parts = Part::query()->with('unit')->findMany($movements->pluck('part_id')->unique())->keyBy('id');
            $warehouses = Warehouse::query()->findMany($movements->pluck('warehouse_id')->unique())->keyBy('id');

            // Önce eksiye düşecek satırlar toplu kontrol edilir (tüm eksikler tek seferde listelensin).
            $deltas = [];
            foreach ($movements as $movement) {
                if (! $movement->type->isProductionFlow() || $movement->type === StockMovementType::Shipment) {
                    throw new BusinessRuleException('Yalnızca üretim hareketleri geri alınabilir.');
                }

                if (StockMovement::query()->where('corrected_movement_id', $movement->getKey())->exists()) {
                    throw new BusinessRuleException('Bu üretimin hareketleri zaten ters kaydedilmiş.');
                }

                $key = $this->key($movement->part_id, $movement->warehouse_id);
                $deltas[$key] = Quantity::add($deltas[$key] ?? '0', Quantity::negate((string) $movement->quantity));
            }

            $shortages = [];
            foreach ($deltas as $key => $delta) {
                $balance = $balances[$key];
                $part = $parts[$balance->part_id];
                $warehouse = $warehouses[$balance->warehouse_id];
                $available = (string) $balance->quantity;

                if (! $warehouse->allowsNegativeFor($part) && Quantity::compare(Quantity::add($available, $delta), '0') < 0) {
                    $shortages[] = $this->shortage($part, Quantity::negate($delta), $available, $warehouse);
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            return $movements->map(function (StockMovement $movement) use ($parts, $warehouses, $balances, $user, $description, $reference): StockMovement {
                $part = $parts[$movement->part_id];
                $this->ensureTypeAllowed($part, StockMovementType::Correction);

                return $this->apply(
                    $part,
                    $warehouses[$movement->warehouse_id],
                    $balances[$this->key($movement->part_id, $movement->warehouse_id)],
                    StockMovementType::Correction,
                    Quantity::negate((string) $movement->quantity),
                    $user,
                    $description,
                    correctedMovement: $movement,
                    reference: $reference,
                );
            })->values();
        });
    }

    /**
     * Alış faturasıyla gelen malın girişi (stock_in, referans fatura). Bağlanacak stok girişi olmayan alış
     * faturası satırı için InvoiceService çağırır.
     */
    public function receiveForInvoice(Part $part, string|int $quantity, Model $reference, User $user, string $description, ?Warehouse $warehouse = null): StockMovement
    {
        $quantity = $this->positive($quantity);

        return $this->single($part, $warehouse, StockMovementType::StockIn, $quantity, $reference, $user, $description);
    }

    /**
     * Sevk: mamul kartından düşüm (shipment). Referans sevk edilen motor kaydıdır.
     */
    public function recordShipment(Part $part, string|int $quantity, Model $reference, User $user, ?string $description = null, ?Warehouse $warehouse = null): StockMovement
    {
        $quantity = Quantity::negate($this->positive($quantity));

        return $this->single($part, $warehouse, StockMovementType::Shipment, $quantity, $reference, $user, $description);
    }

    private function single(Part $part, ?Warehouse $warehouse, StockMovementType $type, string $delta, Model $reference, User $user, ?string $description): StockMovement
    {
        return DB::transaction(function () use ($part, $warehouse, $type, $delta, $reference, $user, $description): StockMovement {
            $warehouse ??= $this->warehouses->defaultFor($part);
            $balance = $this->lockBalances([[$part->getKey(), $warehouse->getKey()]])->first();
            $part = Part::query()->with('unit')->findOrFail($part->getKey());
            $warehouse = Warehouse::query()->findOrFail($warehouse->getKey());
            $this->ensureTypeAllowed($part, $type);
            $this->ensureWarehouseAllowed($warehouse, $type);
            $this->ensureUnitPrecision($part, $delta);

            return $this->apply($part, $warehouse, $balance, $type, $delta, $user, $description, reference: $reference);
        });
    }

    /**
     * @param  Closure(Part, string): ?string  $resolveDelta  Kilitli bakiyeye göre işaretli miktar; null ise hareket yok.
     */
    private function record(
        Part $part,
        ?Warehouse $warehouse,
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
            return DB::transaction(function () use ($part, $warehouse, $type, $resolveDelta, $user, $description, $idempotencyKey, $correctedMovement): ?StockMovement {
                $warehouse ??= $this->warehouses->defaultFor($part);
                $balance = $this->lockBalances([[$part->getKey(), $warehouse->getKey()]])->first();

                // Kilit alındıktan sonra bakılır: aynı anahtarlı eşzamanlı istek, ilki commit edince bunu görür.
                if ($idempotencyKey !== null && ($existing = StockMovement::query()->firstWhere('idempotency_key', $idempotencyKey))) {
                    return $existing;
                }

                // Ürünün ve deponun güncel hâli (pasifleştirme, birim değişikliği) kilit altında okunur.
                $part = Part::query()->with('unit')->findOrFail($part->getKey());
                $warehouse = Warehouse::query()->findOrFail($warehouse->getKey());

                $this->ensureTypeAllowed($part, $type);
                $this->ensureWarehouseAllowed($warehouse, $type);

                $delta = $resolveDelta($part, (string) $balance->quantity);

                if ($delta === null) {
                    return null;
                }

                $this->ensureUnitPrecision($part, $delta);

                return $this->apply($part, $warehouse, $balance, $type, $delta, $user, $description, $idempotencyKey, $correctedMovement);
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
        Warehouse $warehouse,
        StockBalance $balance,
        StockMovementType $type,
        string $delta,
        User $user,
        ?string $description,
        ?string $idempotencyKey = null,
        ?StockMovement $correctedMovement = null,
        ?Model $reference = null,
        ?string $transferId = null,
    ): StockMovement {
        $before = (string) $balance->quantity;
        $after = Quantity::add($before, $delta);

        if (Quantity::compare($after, '0') < 0 && ! $warehouse->allowsNegativeFor($part)) {
            throw new InsufficientStockException([$this->shortage($part, Quantity::negate($delta), $before, $warehouse)]);
        }

        $movement = new StockMovement;
        $movement->forceFill([
            'part_id' => $part->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'type' => $type,
            'quantity' => $delta,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'corrected_movement_id' => $correctedMovement?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'transfer_id' => $transferId,
            'description' => $description,
            'user_id' => $user->getKey(),
        ])->save();

        $balance->quantity = $after;
        $this->syncCriticalState($part, $warehouse, $balance);
        $balance->save();

        return $movement;
    }

    /**
     * Kritik stok bildirimi (depo bazlı) yalnızca kritik seviyeye ilk inişte gönderilir; bakiye tekrar kritik
     * seviyenin üstüne çıkana kadar aynı ürün ve depo için tekrar gönderilmez. Bildirim commit sonrası gider
     * (geri alınan işlem bildirim üretmez).
     */
    private function syncCriticalState(Part $part, Warehouse $warehouse, StockBalance $balance): void
    {
        $isCritical = $balance->isCriticalAt();

        if ($isCritical && ! $balance->is_below_critical) {
            $balance->is_below_critical = true;
            $quantity = (string) $balance->quantity;
            $level = (string) $balance->critical_level;

            DB::afterCommit(fn () => $this->alerts->criticalStock($part, $warehouse, $quantity, $level));
        } elseif (! $isCritical && $balance->is_below_critical) {
            $balance->is_below_critical = false;
        }
    }

    /**
     * Bakiye satırlarını (part_id, warehouse_id) sırasıyla kilitler; satırı olmayan depo için 0 bakiyeli satır
     * açılır (kritik seviye 0).
     *
     * @param  list<array{0: int, 1: int}>  $pairs  [part_id, warehouse_id]
     * @return Collection<string, StockBalance> "part_id:warehouse_id" => bakiye
     */
    private function lockBalances(array $pairs): Collection
    {
        $pairs = collect($pairs)
            ->map(fn (array $pair) => [(int) $pair[0], (int) $pair[1]])
            ->unique(fn (array $pair) => $this->key(...$pair))
            ->sortBy([[0, 'asc'], [1, 'asc']])
            ->values();

        $query = fn () => StockBalance::query()->where(function ($q) use ($pairs): void {
            foreach ($pairs as [$partId, $warehouseId]) {
                $q->orWhere(fn ($q) => $q->where('part_id', $partId)->where('warehouse_id', $warehouseId));
            }
        });

        $lock = fn () => $query()
            ->orderBy('part_id')
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (StockBalance $b) => $this->key($b->part_id, $b->warehouse_id));

        $balances = $lock();

        // Satırı olmayan depolar için 0 bakiyeli satır açılır ve tümü yeniden (sırayla) kilitlenir.
        $missing = $pairs->reject(fn (array $pair) => $balances->has($this->key(...$pair)));

        if ($missing->isNotEmpty()) {
            StockBalance::query()->insertOrIgnore($missing->map(fn (array $pair) => [
                'part_id' => $pair[0], 'warehouse_id' => $pair[1], 'quantity' => 0, 'critical_level' => 0,
                'is_below_critical' => false, 'updated_at' => now(),
            ])->all());

            $balances = $lock();
        }

        if ($balances->count() !== $pairs->count()) {
            throw new BusinessRuleException('Ürünün stok bakiyesi bulunamadı.');
        }

        return $balances;
    }

    private function key(int $partId, int $warehouseId): string
    {
        return "{$partId}:{$warehouseId}";
    }

    private function ensureTypeAllowed(Part $part, StockMovementType $type): void
    {
        // Veritabanında stock_movements_validate_insert trigger'ı da zorlar.
        if ($part->type === PartType::FinishedGood && ! $type->allowedOnFinishedGood()) {
            throw new BusinessRuleException("{$part->code} {$part->name} bir mamul kartı. Mamul stoğu elle değiştirilemez; yalnızca üretim ve sevkle değişir.");
        }

        if (! $part->is_active && ! $type->allowedOnInactivePart()) {
            throw new BusinessRuleException("{$part->code} {$part->name} pasif. Pasif ürüne yalnızca sayım yapılabilir.");
        }
    }

    /** Pasif depoda yalnızca sayım, ters kayıt ve başka depoya transfer (depoyu boşaltmak için). */
    private function ensureWarehouseAllowed(Warehouse $warehouse, StockMovementType $type, bool $outgoing = false): void
    {
        if ($warehouse->is_active) {
            return;
        }

        $allowed = in_array($type, [StockMovementType::CountAdjustment, StockMovementType::Correction], true)
            || ($type === StockMovementType::Transfer && $outgoing);

        if (! $allowed) {
            throw new BusinessRuleException("{$warehouse->name} pasif. Pasif depoda yalnızca sayım ve başka depoya transfer yapılabilir.");
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

    private function criticalLevelValue(Part $part, string|int|float $level): string
    {
        $level = Quantity::parse($level);

        if (Quantity::compare($level, '0') < 0) {
            throw new BusinessRuleException('Kritik seviye negatif olamaz.');
        }

        $unit = Unit::query()->findOrFail($part->unit_id);
        if (! $unit->allows_decimal && ! Quantity::isWhole($level)) {
            throw new BusinessRuleException("Kritik seviye tam sayı olmalıdır (birim: {$unit->name}).");
        }

        return $level;
    }

    /**
     * @return array{part_id: int, code: string, name: string, unit: string, required: string, available: string, missing: string, warehouse: ?string}
     */
    private function shortage(Part $part, string $required, string $available, ?Warehouse $warehouse = null): array
    {
        return [
            'part_id' => $part->getKey(),
            'code' => $part->code,
            'name' => $part->name,
            'unit' => $part->unit->name,
            'required' => $required,
            'available' => $available,
            'missing' => Quantity::sub($required, $available),
            'warehouse' => $warehouse?->name,
        ];
    }
}
