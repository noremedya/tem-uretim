<?php

namespace App\Imports;

/**
 * Firmanın anlattığı ürün yapısından önceden doldurulmuş başlangıç dosyası (Aşama E): sac şerit genişlikleri, mil
 * çapları, klemens kutuları, pervaneler ve koruma tasları, eksantrik saç, sarflar; 71 tip örnek motor, pres (stator +
 * rotor paketi eş ürün, sac firesi hurdaya) ve mil reçetesi. Firma eksik ölçü/kodları tamamlayıp yükler.
 */
final class StarterCatalog
{
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

        foreach ($sheets as [$width, $for]) {
            $parts[] = self::part("HM-SAC-050-{$width}", 'Silisli sac M470 0,50 x '.ltrim($width, '0')." mm ({$for})", 'Hammadde', 'Silisli sac', 'Kg');
        }

        foreach ($shafts as [$diameter, $for]) {
            $parts[] = self::part("HM-MIL-{$diameter}", "Mil çeliği Ø{$diameter} mm ({$for})", 'Hammadde', 'Mil çeliği', 'Kg');
        }

        $parts[] = self::part('HM-ALU', 'Alüminyum (rotor enjeksiyonu)', 'Hammadde', 'Alüminyum', 'Kg');
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
        $parts[] = self::part('YM-STP-71-070', 'Stator paketi 71 tip 70 mm', 'Yarı mamul', 'Stator paketi', 'Adet', 'Örnek: her motor tipi ve paket boyu için ayrı satır.');
        $parts[] = self::part('YM-RTP-71-070', 'Rotor paketi 71 tip 70 mm', 'Yarı mamul', 'Rotor paketi', 'Adet', 'Stator paketiyle aynı kalıpta çıkar (Eş ürünler).');
        $parts[] = self::part('YM-MIL-71', 'İşlenmiş mil 71 tip', 'Yarı mamul', 'İşlenmiş mil', 'Adet');

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
