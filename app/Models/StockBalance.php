<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parçanın güncel stok bakiyesi (hızlı okuma için). Tek yazıcısı StockService'tir.
 */
class StockBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_below_critical' => 'boolean',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
