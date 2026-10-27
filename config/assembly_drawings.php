<?php

/*
 * Montaj çizimleri ("Motor oluştur" ekranı). Şablon (bom_templates.drawing) bir çizime, şablon satırı
 * (bom_template_lines.drawing_number) çizimdeki parça numarasına bağlanır. Görsel uygulamayla birlikte gelir
 * (public/images/montaj, CDN yok). markers: parça no => çizimdeki numara etiketlerinin merkezleri (piksel,
 * görselin kendi boyutunda); aynı numara çizimde birden fazla yerde olabilir.
 *
 * Yeni motor tipi için: görseli klasöre koyun, buraya ekleyin, şablonda seçin.
 */
return [
    'b3-trifaze' => [
        'label' => 'B3 trifaze elektrik motoru montaj parça listesi',
        'image' => 'images/montaj/b3-trifaze.jpg',
        'width' => 800,
        'height' => 710,
        'source' => 'temmotor.com',
        'markers' => [
            1 => [[48, 340]],
            2 => [[48, 290]],
            3 => [[737, 295]],
            4 => [[48, 385]],
            5 => [[48, 483]],
            6 => [[48, 578]],
            7 => [[737, 393]],
            8 => [[48, 155]],
            9 => [[48, 48]],
            10 => [[48, 197]],
            11 => [[48, 103]],
            12 => [[48, 243], [737, 345]],
            13 => [[737, 198]],
            14 => [[737, 245]],
            15 => [[48, 437], [48, 535]],
        ],
    ],
    'tp-pompa' => [
        'label' => 'TP serisi bor yağı pompası montaj bağlantı listesi',
        'image' => 'images/montaj/tp-pompa.jpg',
        'width' => 800,
        'height' => 1073,
        'source' => 'temmotor.com',
        'markers' => [
            1 => [[115, 60]],
            2 => [[68, 712]],
            3 => [[737, 327]],
            4 => [[737, 637]],
            5 => [[737, 577]],
            6 => [[68, 822]],
            7 => [[737, 1027]],
            8 => [[737, 697]],
            9 => [[555, 60]],
            10 => [[737, 462]],
            11 => [[68, 657], [68, 767]],
            12 => [[565, 1027], [668, 1027]],
            13 => [[445, 60]],
            14 => [[500, 60]],
            15 => [[68, 162], [68, 217], [68, 437], [68, 547]],
            16 => [[170, 60], [68, 107], [68, 492], [68, 602]],
            17 => [[335, 60]],
            18 => [[280, 60]],
            19 => [[225, 60]],
            20 => [[390, 60]],
        ],
    ],
];
