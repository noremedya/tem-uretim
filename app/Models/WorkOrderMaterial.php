<?php

namespace App\Models;

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

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** Fire dahil toplam ihtiyaç (standart fireyle). */
    public function totalRequirement(): string
    {
        return Quantity::add((string) $this->required_quantity, (string) $this->planned_scrap);
    }

    /** Satırın deposundaki bakiye (part.stockBalances yüklüyse sorgu yapmaz). */
    public function available(): string
    {
        return $this->part->quantityIn($this->warehouse_id);
    }
}
