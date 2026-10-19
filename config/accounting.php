<?php

/*
| Muhasebe kodları (Tekdüzen Hesap Planı). Mali müşavirin (Luca) hesap planıyla uyumlu tutulmalı.
| Kod değişirse yalnızca bu dosya güncellenir; uygulama davranışı koda değil türe bağlıdır.
*/
return [
    'part_types' => [
        'raw_material' => '150.01',
        'component' => '150.02',
        'direct_consumable' => '150.03',
        'indirect_consumable' => '157',
        'semi_finished' => '151',
        'finished_good' => '152',
    ],

    /*
    | Dövizli faturada TL karşılığı için kullanılan TCMB kuru: 'forex_selling' (döviz satış) veya
    | 'forex_buying' (döviz alış). Mali müşavirle teyit edilmeli (VUK 280 değerlemede alış kurunu öngörür).
    */
    'invoice_rate_type' => env('INVOICE_RATE_TYPE', 'forex_selling'),

    /*
    | İhraç kayıtlı satışta (KDV 11/1-c) ihracatçının malı ihraç etmesi gereken süre, fatura tarihinden itibaren ay.
    | Süre içinde gümrük beyannamesi işlenmeyen faturalar "süresi geçen" olarak listelenir.
    */
    'registered_export_months' => 3,

    // TCMB günlük kurlar (internet varken exchange-rates:fetch ile çekilir).
    'tcmb_rates_url' => env('TCMB_RATES_URL', 'https://www.tcmb.gov.tr/kurlar/today.xml'),
];
