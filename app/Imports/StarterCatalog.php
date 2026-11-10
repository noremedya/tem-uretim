<?php

namespace App\Imports;

/**
 * Firmanın anlattığı ürün yapısından önceden doldurulmuş başlangıç dosyası (Aşama E): sac şerit genişlikleri, mil
 * çapları, klemens kutuları, pervaneler ve koruma tasları, eksantrik saç, sarflar; 71 tip örnek motor, pres (stator +
 * rotor paketi eş ürün, sac firesi hurdaya) ve mil reçetesi; firmanın alüminyum parça listesi ve Etial alaşımları. Firma
 * eksik ölçü/kodları tamamlayıp yükler.
 */
final class StarterCatalog
{
    /**
     * Firmanın alüminyum parça listesi: sıra no, ad, alt tür, kalıp göz sayısı, Etial tipi, parça ağırlığı (g). Ağırlık
     * reçeteye net miktar olarak girer; yolluk/döküm firesi bilinmediği için fire yoktur.
     */
    private const ALUMINIUM_PARTS = [
        [1, 'Pompa Gövdesi', 'Gövde', 1, '160', 620],
        [2, '90-B-3- Kapak', 'Kapak', 2, '160', 910],
        [3, '63-B-14- Flanş', 'Flanş', 2, '160', 620],
        [4, '71-Tip. B.14 kapak', 'Kapak', 2, '160', 720],
        [5, 'Pompa Flanşı Pervane', 'Flanş', 3, '140/160', 520],
        [6, '63-AYAK 90 Tip LONG-Ayak', 'Ayak', 8, '160', 1220],
        [7, 'Hava Perdesi', 'Hava perdesi', 2, '160', 720],
        [8, '71-Tip B-3- Kapak', 'Kapak', 2, '160', 640],
        [9, '63-Tip B-3- Kapak', 'Kapak', 2, '160', 470],
        [10, '80-B-3- Kapak', 'Kapak', 2, '160', 730],
        [11, 'Burulor düz. Kapak', 'Kapak', 2, '160', 470],
        [12, 'Burulor Pompalı Kapak', 'Kapak', 2, '160', 540],
        [13, 'KL. 15- FLAŞ', 'Flanş', 2, '160', 530],
        [14, '80-B-14 FLANŞ', 'Flanş', 1, '160', 590],
        [15, 'KL. 18- FLAŞ', 'Flanş', 1, '160', 340],
        [16, '71- B 5 FLANŞ', 'Flanş', 1, '160', 560],
        [17, 'Süt Sağma Pompa Flanşı', 'Flanş', 2, '160', 1700],
        [18, '90 TİP LONG Gövde', 'Gövde', 1, '141', 1360],
        [19, '90 TİP SMALL Gövde', 'Gövde', 1, '141', 1480],
        [20, '80TİP Gövde', 'Gövde', 1, '141', 1360],
        [21, '71TİP Gövde', 'Gövde', 1, '141', 770],
        [22, '63 TİP Gövde', 'Gövde', 1, '141', 700],
        [23, '56 TİP Gövde', 'Gövde', 1, '141', 580],
        [24, 'Süt Sağma Pompa Gövdesi', 'Gövde', 1, '141', 2910],
        [25, 'Yeni Hava Perdesi KAPAKLARI', 'Kapak', 1, '160', 720],
        [26, 'Süt Karıştırma + Hayvan Kaşıma Gövde Kapakları', 'Kapak', 2, '160', 1420],
        [27, 'Vibrasyon motor gövdesi', 'Gövde', 2, '160', 800],
        [28, '90 TİP B14 FLANŞ', 'Flanş', 2, '170', 1300],
    ];

    /** @return list<string> "Nasıl doldurulur" sayfasına eklenen kod düzeni önerisi */
    public static function guide(): array
    {
        return [
            'Bu dosya anlattığınız ürün yapısından önceden doldurulmuştur. Eksik ölçüleri ve kodları tamamlayın, kritik seviyeleri girin; gerekirse satır ekleyin/silin.',
            '',
            'Kod düzeni önerisi (kendi düzeniniz varsa onu kullanın):',
            '  HM- hammadde · KOM- komponent · DS- direkt sarf · ES- endirekt sarf · YM- yarı mamul; ardından ne olduğu ve ölçü.',
            '  Örnekler: HM-SAC-050-110 (sac 0,50 mm, 110 mm şerit) · HM-MIL-20 (mil çeliği Ø20) · YM-STP-71-070 (71 tip 70 mm stator paketi) · KOM-KLM-MD-63-71.',
            '  Motor modeli kodu: gövde-güç-kutup ve tip, ör. M71-037-4 (71 tip 0,37 kW 4 kutup trifaze), M71-037-4-MD (monofaze MD).',
            '',
            'Alüminyum parçaların (YM-ALU-) reçetesi parça ağırlığıyla doludur; yolluk ve döküm firesi girilmedi, biliniyorsa ekleyin.',
            'Pompa Flanşı Pervane 140 veya 160 ile dökülebilir; reçetede 160 yazılıdır, 140 kullanılan üretimde malzeme elle değiştirilir.',
            'Henüz bilinmeyenler (doldurun): bakır tel çapları, rulman ve kondansatör tipleri, her motor için stator/rotor paket boyları ve sac brüt/fire ağırlıkları.',
            'Vibrasyon motorunda pervane yoktur; koruma tası eksantrik saçları korur.',
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function rows(): array
    {
        $sheets = [
            ['072', 'vibrasyon'], ['091', '56 tip'], ['102', '63 tip'], ['110', '71 tip'], ['126', '80 tip'], ['140', '90 tip'],
        ];
        $shafts = [
            ['16', '56 tip, 80 tip HPM'], ['20', '63 ve 71 tip, vibrasyon'], ['25', '80 tip'], ['26', '63 tip özel motorlar'], ['30', '90 tip'],
        ];

        $parts = [];
        $aluminiumBom = [];

        foreach ($sheets as [$width, $for]) {
            $parts[] = self::part("HM-SAC-050-{$width}", 'Silisli sac M470 0,50 x '.ltrim($width, '0')." mm ({$for})", 'Hammadde', 'Silisli sac', 'Kg');
        }

        foreach ($shafts as [$diameter, $for]) {
            $parts[] = self::part("HM-MIL-{$diameter}", "Mil çeliği Ø{$diameter} mm ({$for})", 'Hammadde', 'Mil çeliği', 'Kg');
        }

        $parts[] = self::part('HM-ALU', 'Alüminyum (rotor enjeksiyonu)', 'Hammadde', 'Alüminyum', 'Kg');

        foreach (['140', '141', '160', '170'] as $alloy) {
            $parts[] = self::part("HM-ALU-ETIAL-{$alloy}", "Alüminyum alaşımı Etial {$alloy}", 'Hammadde', 'Alüminyum', 'Kg');
        }

        $parts[] = self::part('HM-BAKIR', 'Emaye bakır tel', 'Hammadde', 'Bakır tel', 'Kg', 'Kullanılan her tel çapı için ayrı satır açın (ör. HM-BAKIR-063).');
        $parts[] = self::part('HM-HURDA-SAC', 'Sac hurdası (pres firesi)', 'Hammadde', null, 'Kg', 'Pres firesi bu karta girer (reçetede fire kartı).');

        foreach ([['TRF', '56-71', 'trifaze 56-63-71 tip'], ['TRF', '80-90', 'trifaze 80-90 tip'], ['MD', '63-71', 'monofaze MD (tek kondansatör) 63-71 tip'],
            ['MD', '80-90', 'monofaze MD (tek kondansatör) 80-90 tip'], ['MSD', '63-71', 'monofaze MSD (iki kondansatör) 63-71 tip'], ['MSD', '80-90', 'monofaze MSD (iki kondansatör) 80-90 tip']] as [$kind, $frames, $name]) {
            $parts[] = self::part("KOM-KLM-{$kind}-{$frames}", "Klemens kutusu {$name}", 'Komponent', 'Klemens kutusu', 'Adet');
        }

        foreach (['56' => '56 tip', '63-71' => '63-71 tip', '80' => '80 tip', '90' => '90 tip'] as $code => $for) {
            $parts[] = self::part("KOM-PRV-{$code}", "Pervane (soğutma fanı, plastik) {$for}", 'Komponent', 'Pervane', 'Adet');
        }

        foreach (['56', '63', '71', '80', '90'] as $frame) {
            $parts[] = self::part("KOM-PKT-{$frame}", "Pervane koruma tası {$frame} tip", 'Komponent', 'Pervane koruma tası', 'Adet');
        }

        $parts[] = self::part('KOM-PKT-VIB', 'Koruma tası vibrasyon (eksantrik saç koruması)', 'Komponent', 'Pervane koruma tası', 'Adet');
        $parts[] = self::part('KOM-EKS-VIB', 'Eksantrik saç (vibrasyon)', 'Komponent', 'Eksantrik saç', 'Adet');
        $parts[] = self::part('DS-MAKARON', 'Makaron', 'Direkt sarf', 'Makaron', 'Metre');
        $parts[] = self::part('DS-KABLO', 'Bağlantı kablosu', 'Direkt sarf', 'Kablo', 'Metre');
        $parts[] = self::part('DS-VERNIK', 'Emprenye verniği', 'Direkt sarf', 'Vernik', 'Kg');
        // Ambalaj ve boya motor reçetesinden motor başına düşer; şerit bant ve koli bandı depodan toplu gider çıkışıyla (karar 2026-11-10).
        $parts[] = self::part('DS-BOYA', 'Motor boyası', 'Direkt sarf', 'Boya', 'Kg');
        $parts[] = self::part('DS-KOLI-71', 'Koli 71 tip', 'Direkt sarf', 'Koli', 'Adet', 'Her koli ölçüsü için ayrı satır.');
        $parts[] = self::part('DS-KOPUK', 'Köpük (ambalaj)', 'Direkt sarf', 'Köpük', 'Adet');
        $parts[] = self::part('DS-BALONLU', 'Balonlu poşet', 'Direkt sarf', 'Balonlu poşet', 'Adet');
        $parts[] = self::part('ES-SERIT-BANT', 'Şerit bant', 'Endirekt sarf', 'Bant', 'Adet', 'Rulo; reçeteye girmez, depodan toplu çıkış (gider).');
        $parts[] = self::part('ES-KOLI-BANDI', 'Koli bandı', 'Endirekt sarf', 'Bant', 'Adet', 'Rulo; reçeteye girmez, depodan toplu çıkış (gider).');
        $parts[] = self::part('YM-STP-71-070', 'Stator paketi 71 tip 70 mm', 'Yarı mamul', 'Stator paketi', 'Adet', 'Örnek: her motor tipi ve paket boyu için ayrı satır.');
        $parts[] = self::part('YM-RTP-71-070', 'Rotor paketi 71 tip 70 mm', 'Yarı mamul', 'Rotor paketi', 'Adet', 'Stator paketiyle aynı kalıpta çıkar (Eş ürünler).');
        $parts[] = self::part('YM-MIL-71', 'İşlenmiş mil 71 tip', 'Yarı mamul', 'İşlenmiş mil', 'Adet');

        foreach (self::ALUMINIUM_PARTS as [$number, $name, $subtype, $cavities, $alloy, $weight]) {
            $code = sprintf('YM-ALU-%02d', $number);
            $parts[] = self::part($code, $name, 'Yarı mamul', $subtype, 'Adet', "Kalıp {$cavities} göz · Etial {$alloy} · Ağırlık {$weight} g");
            // İki alaşımla dökülebilen parçada reçete sondakini kullanır (Pompa Flanşı Pervane: 160).
            $component = 'HM-ALU-ETIAL-'.substr($alloy, -3);
            $aluminiumBom[] = ['parent' => $code, 'component' => $component, 'net' => str_replace('.', ',', (string) ($weight / 1000))];
        }

        return [
            CatalogSheets::UNITS => [
                ['name' => 'Adet', 'allows_decimal' => 'Hayır'],
                ['name' => 'Kg', 'allows_decimal' => 'Evet'],
                ['name' => 'Metre', 'allows_decimal' => 'Evet'],
            ],
            CatalogSheets::PARTS => $parts,
            CatalogSheets::MODELS => [
                [
                    'code' => 'M71-037-4', 'name' => '71 Tip 0,37 kW 1500 d/dk Trifaze', 'subtype' => 'Trifaze', 'power_kw' => '0,37',
                    'speed_rpm' => '1500', 'pole_count' => '4', 'frame_size' => '71', 'phase' => 'Trifaze', 'frequency_hz' => '50',
                    'description' => 'Örnek model: her güç/devir için ayrı satır açın.',
                ],
            ],
            CatalogSheets::BOM => [
                ['parent' => 'YM-STP-71-070', 'component' => 'HM-SAC-050-110', 'gross' => '6,61', 'scrap_quantity' => '2,88', 'scrap_part' => 'HM-HURDA-SAC'],
                ['parent' => 'YM-MIL-71', 'component' => 'HM-MIL-20', 'gross' => '2,2', 'scrap_quantity' => '0,5'],
                ...$aluminiumBom,
            ],
            CatalogSheets::CO_PRODUCTS => [
                ['parent' => 'YM-STP-71-070', 'co' => 'YM-RTP-71-070', 'quantity' => '1'],
            ],
        ];
    }

    private static function part(string $code, string $name, string $type, ?string $subtype, string $unit, ?string $description = null): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'unit' => $unit, 'description' => $description];
    }
}
