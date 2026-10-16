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
];
