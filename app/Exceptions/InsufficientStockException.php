<?php

namespace App\Exceptions;

use App\Support\Quantity;

/**
 * Yetersiz stok. Birden fazla malzemenin eksikliği tek seferde listelenir.
 */
class InsufficientStockException extends BusinessRuleException
{
    /**
     * @param  list<array{part_id: int, code: string, name: string, unit: string, required: string, available: string, missing: string, warehouse?: ?string}>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        $lines = array_map(
            fn (array $s): string => sprintf(
                '%s %s%s: gereken %s, mevcut %s, eksik %s',
                $s['code'],
                $s['name'],
                filled($s['warehouse'] ?? null) ? " ({$s['warehouse']})" : '',
                Quantity::format($s['required'], $s['unit']),
                Quantity::format($s['available'], $s['unit']),
                Quantity::format($s['missing'], $s['unit']),
            ),
            $shortages,
        );

        parent::__construct("Yetersiz stok. İşlem yapılmadı.\n".implode("\n", $lines));
    }
}
