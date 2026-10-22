<?php

namespace App\Imports;

/**
 * Ürün yapısı içe aktarma şablonunun sayfaları ve sütunları (sırası aktarma sırasıdır). Başlıklar Türkçe; "*" zorunlu.
 * Okurken başlık büyük/küçük harf, Türkçe karakter, "*" ve boşluk farkı önemsizdir.
 */
final class CatalogSheets
{
    public const UNITS = 'units';

    public const PARTS = 'parts';

    public const MODELS = 'models';

    public const BOM = 'bom';

    public const CO_PRODUCTS = 'co_products';

    public const OPENING = 'opening';

    /** @var array<string, array{title: string, columns: array<string, string>, required: list<string>, note: string}> */
    public const SHEETS = [
        self::UNITS => [
            'title' => 'Birimler',
            'columns' => ['name' => 'Ad*', 'allows_decimal' => 'Küsurata izin'],
            'required' => ['name'],
            'note' => 'Olmayan birimler açılır (var olan birim değişmez). Küsurata izin: Evet (kg, metre) / Hayır (adet).',
        ],
        self::PARTS => [
            'title' => 'Ürünler',
            'columns' => [
                'code' => 'Kod*', 'name' => 'Ad*', 'type' => 'Tür*', 'subtype' => 'Alt tür', 'unit' => 'Birim*', 'brand' => 'Marka',
                'barcode' => 'Barkod', 'warehouse' => 'Varsayılan depo', 'critical_level' => 'Kritik seviye', 'description' => 'Açıklama',
            ],
            'required' => ['code', 'name', 'type', 'unit'],
            'note' => 'Tür: Hammadde, Komponent, Direkt sarf, Endirekt sarf, Yarı mamul (veya muhasebe kodu: 150.01…). Mamul bu sayfaya yazılmaz (Motor modelleri). Alt tür ve marka yoksa açılır; birim ve depo tanımlı olmalı.',
        ],
        self::MODELS => [
            'title' => 'Motor modelleri',
            'columns' => [
                'code' => 'Kod*', 'name' => 'Ad*', 'subtype' => 'Alt tür', 'power_kw' => 'Güç (kW)', 'speed_rpm' => 'Devir (d/dk)',
                'voltage' => 'Gerilim', 'frequency_hz' => 'Frekans (Hz)', 'pole_count' => 'Kutup sayısı', 'frame_size' => 'Gövde ölçüsü',
                'phase' => 'Faz', 'mounting_type' => 'Montaj şekli', 'protection_class' => 'Koruma sınıfı', 'efficiency_class' => 'Verim sınıfı',
                'gtip_code' => 'GTİP', 'net_weight_kg' => 'Net ağırlık (kg)', 'description' => 'Açıklama',
            ],
            'required' => ['code', 'name'],
            'note' => 'Mamul kartı modelle birlikte açılır (kod ve ad aynı). Alt tür: Trifaze, Monofaze MD, Monofaze MSD, Vibrasyon… Faz: Trifaze / Monofaze.',
        ],
        self::BOM => [
            'title' => 'Reçeteler',
            'columns' => [
                'parent' => 'Üst ürün kodu*', 'component' => 'Bileşen kodu*', 'net' => 'Net miktar', 'scrap_rate' => 'Fire oranı (%)',
                'gross' => 'Brüt miktar', 'scrap_quantity' => 'Fire miktarı', 'scrap_part' => 'Fire kartı kodu',
            ],
            'required' => ['parent', 'component'],
            'note' => 'Birim başına. Ya "Net miktar" (+ isteğe bağlı fire oranı) ya "Brüt miktar" + "Fire miktarı" (net = brüt − fire). Üst ürün yarı mamul veya motor modeli kodu. Fire kartı: fire (hurda) stoğa girecekse.',
        ],
        self::CO_PRODUCTS => [
            'title' => 'Eş ürünler',
            'columns' => ['parent' => 'Ana ürün kodu*', 'co' => 'Eş ürün kodu*', 'quantity' => 'Birim başına*'],
            'required' => ['parent', 'co', 'quantity'],
            'note' => 'Ana yarı mamulle aynı işlemde çıkan yarı mamul (ör. aynı kalıpta stator paketi → rotor paketi). Eş ürünün reçetesi olmaz.',
        ],
        self::OPENING => [
            'title' => 'Açılış stoğu',
            'columns' => ['code' => 'Ürün kodu*', 'warehouse' => 'Depo*', 'quantity' => 'Sayılan miktar*', 'description' => 'Açıklama'],
            'required' => ['code', 'warehouse', 'quantity'],
            'note' => 'Her satır o depoda sayım düzeltmesidir: bakiye sayılan miktara getirilir. Mamul (motor) bu sayfaya yazılmaz.',
        ],
    ];

    /** Başlık karşılaştırması için: küçük harf, Türkçe karakter sadeleştirme, "*" ve fazla boşluk yok. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim($text)), 'UTF-8');
        $text = strtr($text, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', '*' => '']);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
