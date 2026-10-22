<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** İş emrinin eş ürün çıktısı: oluşturulurken reçetenin eş ürünlerinden kopyalanır; tamamlamada stoğa girer. */
class WorkOrderOutput extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity_per_unit' => 'decimal:3', 'quantity' => 'decimal:3'];
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
