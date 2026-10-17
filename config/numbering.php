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
    'work_order' => env('NUMBER_FORMAT_WORK_ORDER', 'IE-{YIL}-{SIRA:5}'),
    // Sıra, kapsam (model kodu + yıl/ay) bazında sıfırlanır.
    // Bizim kestiğimiz faturalar (satış faturası, alış iadesi) tek seri.
    'invoice' => env('NUMBER_FORMAT_INVOICE', 'FTR-{YIL}-{SIRA:6}'),
    'serial_number' => env('NUMBER_FORMAT_SERIAL', '{MODEL_KODU}-{YY}{AA}-{SIRA:5}'),
];
