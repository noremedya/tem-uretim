<?php

/*
 * Otomatik numara şablonları (App\Services\NumberSequenceService).
 *
 * Belirteçler: {YIL} (4 haneli yıl), {YY} (2 haneli yıl), {AA} (ay), {GG} (gün), {SIRA:n} (n haneli sıra, zorunlu)
 * ve çağıranın verdiği bağlam değerleri (ör. {MODEL_KODU}).
 *
 * Sayaç kapsamı şablonun sıra dışındaki kısmıdır: "SIP-{YIL}-{SIRA:5}" için sıra her yıl sıfırlanır.
 * 'continuous' => true olan şablonda sıra tarihle sıfırlanmaz; 'start' ilk sıradır.
 */

return [
    'order' => env('NUMBER_FORMAT_ORDER', 'SIP-{YIL}-{SIRA:5}'),
    // Parti (lot) numarası: iş emri açıldığı ay/yıl + hiç sıfırlanmayan sıra (ör. 1126-1002000).
    'production_batch' => [
        'format' => env('NUMBER_FORMAT_BATCH', '{AA}{YY}-{SIRA:7}'),
        'start' => (int) env('NUMBER_BATCH_START', 1002000),
        'continuous' => true,
    ],
    // İş emri numarası partinin numarasıdır, önüne ek gelir (ör. URT-1126-1002000). "IE" verim sınıfıyla karışır.
    'work_order' => env('NUMBER_FORMAT_WORK_ORDER', 'URT-{PARTI_NO}'),
    // Bizim kestiğimiz faturalar (satış faturası, alış iadesi) tek seri.
    'invoice' => env('NUMBER_FORMAT_INVOICE', 'FTR-{YIL}-{SIRA:6}'),
    // Seri no (motorun metal etiketindeki tip numarası): üretim günü numaradan okunur (kısım kısım üretimde her
    // motor kendi günüyle). Sıra model ve gün bazında sıfırlanır.
    'serial_number' => env('NUMBER_FORMAT_SERIAL', '{MODEL_KODU}-{YY}{AA}{GG}-{SIRA:5}'),
];
