<?php

/*
 * Etiket yazdırma (CLAUDE.md bölüm 7, Aşama 12). Ölçüler mm. Yazdırma sayfasında düzen seçilir.
 * Yazıcıda "gerçek boyut / %100" ve kenar boşluğu "yok" seçilmelidir.
 */
return [
    // Etiketlerde yazan firma adı; her kurulumda .env'den (COMPANY_NAME) verilir.
    'company' => env('COMPANY_NAME', 'TEM MOTOR'),

    'default_layout' => 'thermal',

    // Tek seferde yazdırılabilecek en fazla etiket.
    'max_labels' => 500,

    'layouts' => [
        // Termal etiket yazıcısı: sayfa başına bir etiket.
        'thermal' => [
            'label' => 'Termal etiket (100×50 mm)',
            'page_width' => 100, 'page_height' => 50,
            'columns' => 1, 'rows' => 1,
            'label_width' => 100, 'label_height' => 50,
            'margin_top' => 0, 'margin_left' => 0,
        ],
        // A4 yapışkanlı etiket kağıdı: 3 sütun × 8 satır.
        'a4' => [
            'label' => 'A4 etiket kağıdı (3×8, 70×37 mm)',
            'page_width' => 210, 'page_height' => 297,
            'columns' => 3, 'rows' => 8,
            'label_width' => 70, 'label_height' => 37,
            'margin_top' => 0.5, 'margin_left' => 0,
        ],
    ],
];
