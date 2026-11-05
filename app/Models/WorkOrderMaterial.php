<?php

namespace App\Models;

use App\Services\BomService;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İş emrinin malzeme satırı: iş emri oluşturulurken (veya "reçeteyi yenile" ile) reçeteden kopyalanır.
 */
class WorkOrderMaterial extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:3',
            'scrap_rate' => 'decimal:2',
            'required_quantity' => 'decimal:3',
            'planned_scrap' => 'decimal:3',
            'actual_scrap' => 'decimal:3',
            'scrap_quantity_per_unit' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** Malzemenin düşüleceği depo.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /** Gerçekleşen firenin girdiği fire (hurda) kartı.
     *
     * @return BelongsTo<Part, $this>
     */
    public function scrapPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'scrap_part_id');
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * Fire dahil kalan ihtiyaç (standart fireyle). İş emri yüklüyse (workOrder ilişkisi) daha önce üretilen kısmın
     * ihtiyacı düşülür (kısım kısım üretim); yüklü değilse iş emrinin tamamı.
     */
    public function totalRequirement(): string
    {
        $total = Quantity::add((string) $this->required_quantity, (string) $this->planned_scrap);

        if (! $this->relationLoaded('workOrder') || ! $this->workOrder->hasProduction()) {
            return $total;
        }

        $produced = (string) $this->workOrder->produced_quantity;
        $whole = ! $this->part->unit->allows_decimal;

        return Quantity::sub($total, Quantity::add($this->netFor($produced, $whole), $this->standardScrapFor($produced, $whole)));
    }

    /**
     * $produced adet üretildiğinde toplam net sarf (tam sayılı birimde yukarı). Kısım kısım üretimde kaydın sarfı
     * = netFor(sonra) − netFor(önce); son kayıtta toplam ihtiyaca eşitlenir.
     */
    public function netFor(string $produced, bool $wholeUnit): string
    {
        $net = Quantity::mul($produced, (string) $this->quantity_per_unit);

        return $wholeUnit ? Quantity::ceil($net, 0) : $net;
    }

    /** $produced adet için toplam standart fire (reçete yöntemiyle; tam sayılı birimde yukarı). */
    public function standardScrapFor(string $produced, bool $wholeUnit): string
    {
        return app(BomService::class)->scrapFor($this->netFor($produced, $wholeUnit), $produced, $this, $wholeUnit);
    }

    /** Satırın deposundaki bakiye (part.stockBalances yüklüyse sorgu yapmaz). */
    public function available(): string
    {
        return $this->part->quantityIn($this->warehouse_id);
    }
}
