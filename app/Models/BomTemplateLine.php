<?php

namespace App\Models;

use App\Enums\PartType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Şablon satırı: kind = component (bileşen) veya co_product (eş ürün). component_type ve isteğe bağlı alt tür, seçilecek
 * ürünü sınırlar. Fire ya oranla (quantity_per_unit + scrap_rate) ya miktarla (gross_per_unit + scrap_quantity_per_unit).
 * Boş miktar: şablon uygulanırken girilmesi zorunlu.
 */
class BomTemplateLine extends Model
{
    public const KIND_COMPONENT = 'component';

    public const KIND_CO_PRODUCT = 'co_product';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'component_type' => PartType::class,
            'quantity_per_unit' => 'decimal:3',
            'scrap_rate' => 'decimal:2',
            'gross_per_unit' => 'decimal:3',
            'scrap_quantity_per_unit' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<BomTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(BomTemplate::class, 'bom_template_id');
    }

    /** @return BelongsTo<PartSubtype, $this> */
    public function subtype(): BelongsTo
    {
        return $this->belongsTo(PartSubtype::class, 'component_subtype_id');
    }

    public function isCoProduct(): bool
    {
        return $this->kind === self::KIND_CO_PRODUCT;
    }

    public function usesScrapQuantity(): bool
    {
        return $this->gross_per_unit !== null;
    }
}
