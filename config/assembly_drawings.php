<?php

/*
 * Montaj çizimleri ("Motor oluştur" ekranı). Şablon (bom_templates.drawing) bir çizime, şablon satırı
 * (bom_template_lines.drawing_number) çizimdeki parça numarasına bağlanır. Görsel uygulamayla birlikte gelir
 * (public/images/montaj, CDN yok). markers: parça no => çizimdeki numara etiketlerinin merkezleri (piksel,
 * görselin kendi boyutunda); aynı numara çizimde birden fazla yerde olabilir.
 *
 * Yeni motor tipi (ör. TP serisi bor yağı pompası) için: görseli klasöre koyun, buraya ekleyin, şablonda seçin.
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
];
