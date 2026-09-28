<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Number;

/**
 * Stok miktarları için yardımcılar. Miktarlar veritabanında numeric(15,3) tutulur; PHP tarafında
 * kayan nokta hatası olmaması için string olarak taşınır ve bcmath ile hesaplanır.
 */
final class Quantity
{
    public const SCALE = 3;

    /**
     * Kullanıcı girdisini normalize eder: "12,5", "1.234,5", "12.5", 12 → "12.500".
     * En fazla 3 ondalık basamak kabul edilir.
     *
     * @throws BusinessRuleException Geçersiz sayı biçiminde.
     */
    public static function parse(string|int|float $value): string
    {
        $normalized = self::normalizeInput($value);

        if ($normalized === null) {
            throw new BusinessRuleException('Geçersiz miktar. Örnek: 12 veya 12,5 (en fazla 3 ondalık basamak).');
        }

        return $normalized;
    }

    /** Geçerli bir miktar girdisi mi (form doğrulaması için)? */
    public static function isValid(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && self::normalizeInput($value) !== null;
    }

    public static function isWhole(string $quantity): bool
    {
        return bccomp($quantity, bcadd($quantity, '0', 0), self::SCALE) === 0;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function negate(string $quantity): string
    {
        return bcmul($quantity, '-1', self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $quantity): bool
    {
        return self::compare($quantity, '0') === 0;
    }

    /** Türkçe biçim: 1234.5 → "1.234,5". Gereksiz sıfırlar gösterilmez. */
    public static function format(string|int|float|null $quantity, ?string $unit = null, bool $signed = false): string
    {
        $quantity = self::normalizeInput($quantity ?? 0) ?? '0.000';

        $formatted = Number::format((float) $quantity, maxPrecision: self::SCALE, locale: 'tr');

        if ($signed && self::compare($quantity, '0') > 0) {
            $formatted = '+'.$formatted;
        }

        return $unit !== null ? "{$formatted} {$unit}" : $formatted;
    }

    /** Form alanı için: binlik ayırıcısız, virgüllü, gereksiz sıfırsız ("1234.500" → "1234,5"). */
    public static function toInput(string|int|float|null $quantity): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        $quantity = self::normalizeInput($quantity);

        if ($quantity === null) {
            return null;
        }

        return str_replace('.', ',', rtrim(rtrim($quantity, '0'), '.'));
    }

    private static function normalizeInput(string|int|float $value): ?string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', self::SCALE);
        }

        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }

        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if (str_contains($value, ',')) {
            // Türkçe biçim: nokta binlik ayırıcı, virgül ondalık ayırıcı.
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }

        if (! preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', self::SCALE);
    }
}
