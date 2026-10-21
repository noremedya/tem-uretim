<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Fatura satırı. Tutarlar InvoiceService tarafından hesaplanır (kuruş, yarım yukarı).
 */
class InvoiceLine extends Model
{
    use LogsActivity;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'discount_rate' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'line_gross' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_net' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Alış satırında stok girişinin deposu (boşsa ürünün varsayılan deposu).
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

    /** @return BelongsTo<MotorModel, $this> */
    public function motorModel(): BelongsTo
    {
        return $this->belongsTo(MotorModel::class);
    }

    /** Satış satırının seri numaraları. @return BelongsToMany<MotorUnit, $this> */
    public function motorUnits(): BelongsToMany
    {
        return $this->belongsToMany(MotorUnit::class, 'invoice_line_motor_units');
    }

    /** Alış satırının bağlı stok girişleri. @return BelongsToMany<StockMovement, $this> */
    public function stockMovements(): BelongsToMany
    {
        return $this->belongsToMany(StockMovement::class, 'invoice_line_stock_movements');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_id', 'part_id', 'motor_model_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_rate', 'vat_rate', 'line_total', 'gtip_code', 'warehouse_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
