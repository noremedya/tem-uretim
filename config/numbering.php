<?php

/*
 * Otomatik numara şablonları (App\Services\NumberSequenceService).
 *
 * Belirteçler: {YIL} (4 haneli yıl), {YY} (2 haneli yıl), {AA} (ay), {SIRA:n} (n haneli sıra, zorunlu)
 * ve çağıranın verdiği bağlam değerleri (ör. {MODEL_KODU}).
 *
 * Sayaç kapsamı şablonun sıra dışındaki kısmıdır: "SIP-{YIL}-{SIRA:5}" için sıra her yıl sıfırlanır.
 */

return [
    'order' => env('NUMBER_FORMAT_ORDER', 'SIP-{YIL}-{SIRA:5}'),
];
