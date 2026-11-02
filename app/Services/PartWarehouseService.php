<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrderMaterial;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ürün deposu (CLAUDE.md bölüm 5 "Stok"; firma ayarı `stock.part_warehouses`): reçetede geçen her ürün (mamul hariç)
 * kendi deposunu alır. Depo ürün reçeteye girince açılır, ürünün varsayılan deposu olur, başka depolardaki stoğu ve
 * kritik seviyesi oraya taşınır, açık iş emirlerinin malzeme satırları oraya çevrilir. Reçeteden çıkan ürünün deposu kalır.
 */
class PartWarehouseService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly WarehouseService $warehouses,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('stock.part_warehouses');
    }

    /** Verilen ürünlerin depolarını açar. Ayar kapalıysa bir şey yapmaz. */
    public function ensure(Part|int|null ...$parts): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ($parts as $part) {
            if ($part !== null) {
                $this->ensureFor($part instanceof Part ? $part->getKey() : $part);
            }
        }
    }

    /**
     * Mevcut reçetelerdeki tüm ürünler için depo açar; ALU ve MIL gibi alt tür depolarının görevi biter.
     *
     * @return array{warehouses: int, retired: list<string>}
     */
    public function syncAll(): array
    {
        if (! $this->enabled()) {
            throw new BusinessRuleException('Ürün deposu ayarı kapalı (STOCK_PART_WAREHOUSES).');
        }

        return DB::transaction(function (): array {
            $ids = BomItem::query()->pluck('parent_part_id')
                ->merge(BomItem::query()->pluck('component_part_id'))
                ->merge(BomItem::query()->whereNotNull('scrap_part_id')->pluck('scrap_part_id'))
                ->merge(BomCoProduct::query()->pluck('parent_part_id'))
                ->merge(BomCoProduct::query()->pluck('co_part_id'))
                ->unique()->sort()->values();

            $before = Warehouse::query()->whereNotNull('part_id')->count();
            $this->ensure(...$ids->all());

            $retired = [];
            $subtypeWarehouses = PartSubtype::query()->whereNotNull('default_warehouse_id')->pluck('default_warehouse_id')->unique();
            PartSubtype::query()->whereNotNull('default_warehouse_id')->get()->each(function (PartSubtype $subtype): void {
                $subtype->default_warehouse_id = null;
                $subtype->save();
            });

            foreach (Warehouse::query()->whereKey($subtypeWarehouses)->get() as $warehouse) {
                try {
                    $this->warehouses->deactivate($warehouse);
                    $retired[] = $warehouse->code;
                } catch (BusinessRuleException) {
                    // Stoğu veya görevi olan depo açık kalır; rapor edilmez, kullanıcı ekrandan görür.
                }
            }

            return ['warehouses' => Warehouse::query()->whereNotNull('part_id')->count() - $before, 'retired' => $retired];
        });
    }

    private function ensureFor(int $partId): void
    {
        DB::transaction(function () use ($partId): void {
            $part = Part::query()->lockForUpdate()->findOrFail($partId);

            if ($part->isFinishedGood()) {
                return;
            }

            $warehouse = Warehouse::query()->where('part_id', $partId)->first();

            if ($warehouse !== null && $part->default_warehouse_id === $warehouse->getKey()) {
                return;
            }

            $previous = $this->warehouses->defaultIdFor($part);
            $warehouse ??= $this->open($part);

            $part->default_warehouse_id = $warehouse->getKey();
            $part->save();

            $this->moveCriticalLevel($part, $previous, $warehouse);
            $this->moveStock($part, $warehouse);

            WorkOrderMaterial::query()
                ->where('part_id', $partId)
                ->whereHas('workOrder', fn ($q) => $q->whereIn('status', [WorkOrderStatus::Planned->value, WorkOrderStatus::InProgress->value]))
                ->update(['warehouse_id' => $warehouse->getKey()]);
        });
    }

    private function open(Part $part): Warehouse
    {
        $code = mb_strtoupper($part->code);

        if (Warehouse::query()->where('code', $code)->exists()) {
            $code = "{$code}-URUN";
        }

        $warehouse = new Warehouse(['code' => $code, 'name' => mb_substr($part->name, 0, 100)]);
        $warehouse->part_id = $part->getKey();
        $warehouse->save();

        return $warehouse;
    }

    private function moveCriticalLevel(Part $part, ?int $previousId, Warehouse $warehouse): void
    {
        $previous = $previousId === null ? null : Warehouse::query()->find($previousId);
        $level = $previous === null ? '0' : (string) (StockBalance::query()->where('part_id', $part->getKey())->where('warehouse_id', $previous->getKey())->value('critical_level') ?? '0');

        $this->stock->setCriticalLevel($part, $level, $warehouse);

        if ($previous !== null && $previous->isNot($warehouse) && Quantity::compare($level, '0') !== 0) {
            $this->stock->setCriticalLevel($part, '0', $previous);
        }
    }

    /** Pasif üründe transfer yapılamaz; stoğu eski deposunda kalır, ürün aktifleşince ekrandan aktarılır. */
    private function moveStock(Part $part, Warehouse $warehouse): void
    {
        if (! $part->is_active) {
            return;
        }

        $balances = StockBalance::query()->with('warehouse')->where('part_id', $part->getKey())->where('warehouse_id', '!=', $warehouse->getKey())->where('quantity', '>', 0)->get();

        if ($balances->isEmpty()) {
            return;
        }

        $actor = $this->actor();

        foreach ($balances as $balance) {
            $this->stock->transfer($part, $balance->warehouse, $warehouse, (string) $balance->quantity, $actor, "Ürün deposuna aktarım: {$part->code}");
        }
    }

    /** Reçeteyi değiştiren kullanıcı; Excel aktarımı ve komutta ilk yönetici. */
    private function actor(): User
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permission::StockMove)
            ? $user
            : (User::query()->where('is_active', true)->role(Role::Admin->value)->orderBy('id')->first() ?? throw new BusinessRuleException('Stoğu taşımak için aktif yönetici gerekli.'));
    }
}
