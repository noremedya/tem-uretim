<?php

namespace App\Support;

use ResourceBundle;

/**
 * Ülkeler (ISO 3166-1 alpha-2) ve Türkçe adları. Liste PHP intl (ICU) verisinden gelir; ayrı bir liste tutulmaz,
 * internet gerekmez. Ülke olmayan bölge kodları (AB, BM, bilinmeyen vb.) çıkarılır.
 */
final class Country
{
    public const TURKEY = 'TR';

    private const NOT_COUNTRIES = ['EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ'];

    /** @var array<string, string>|null */
    private static ?array $options = null;

    /** @return array<string, string> kod => Türkçe ad (ada göre sıralı, Türkiye başta) */
    public static function options(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $names = [];
        foreach (ResourceBundle::create('tr', 'ICUDATA-region')['Countries'] as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/', (string) $code) && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $names[$code] = $name;
            }
        }

        $collator = new \Collator('tr_TR');
        uasort($names, fn (string $a, string $b) => $collator->compare($a, $b));

        return self::$options = [self::TURKEY => $names[self::TURKEY]] + $names;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::options());
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::options()[$code] ?? $code);
    }
}
