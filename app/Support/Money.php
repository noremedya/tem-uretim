<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Number;

/**
 * Para tutarları: veritabanında numeric(15,2), PHP'de string olarak taşınır ve bcmath ile hesaplanır.
 * Kurlar 6 ondalıklıdır (RATE_SCALE).
 */
final class Money
{
    public const SCALE = 2;

    public const RATE_SCALE = 6;

    /**
     * Türkçe girdiyi normalize eder: "1.234,50", "1234,5", "1234.50", 12 → "1234.50". En fazla $scale ondalık.
     *
     * @throws BusinessRuleException Geçersiz biçimde.
     */
    public static function parse(string|int|float $value, int $scale = self::SCALE): string
    {
        $normalized = self::normalize($value, $scale);

        if ($normalized === null) {
            throw new BusinessRuleException(sprintf('Geçersiz tutar. Örnek: 1.250,50 (en fazla %d ondalık basamak).', $scale));
        }

        return $normalized;
    }

    public static function isValid(mixed $value, int $scale = self::SCALE): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && self::normalize($value, $scale) !== null;
    }

    public static function compare(string $a, string $b, int $scale = self::RATE_SCALE): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    /** Çarpım, $scale ondalığa yarım yukarı yuvarlanır. */
    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul($a, $b, 10), $scale);
    }

    /** $value × $percent / 100, $scale ondalığa yarım yukarı yuvarlanır. */
    public static function percent(string $value, string $percent, int $scale = self::SCALE): string
    {
        return self::round(bcdiv(bcmul($value, $percent, 10), '100', 10), $scale);
    }

    /** Yarım yukarı yuvarlama (negatifte sıfırdan uzağa): round("2.345") = "2.35". */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', 10) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    /** Türkçe biçim: "1234.5" → "1.234,50". */
    public static function format(string|int|float|null $amount, ?string $currency = null, int $scale = self::SCALE): string
    {
        $formatted = Number::format((float) ($amount ?? 0), precision: $scale, locale: 'tr');

        return $currency !== null ? "{$formatted} {$currency}" : $formatted;
    }

    /** Form alanı için: binlik ayırıcısız, virgüllü ("1234.50" → "1234,50"). */
    public static function toInput(string|int|float|null $amount, int $scale = self::SCALE): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $normalized = self::normalize($amount, $scale);

        return $normalized === null ? null : str_replace('.', ',', $normalized);
    }

    private static function normalize(string|int|float $value, int $scale): ?string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', $scale);
        }

        $value = trim(is_float($value) ? number_format($value, $scale, '.', '') : $value);

        // Türkçe biçim: binlik nokta, ondalık virgül.
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif ($scale < self::RATE_SCALE && preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+$/', $value)) {
            // Virgülsüz "10.000" / "1.250.000" tutarda binlik ayırıcıdır (Türkçe yazım). "0.045", "12.5" ondalık kalır.
            // Kurlarda uygulanmaz ("41.987" bir kurdur).
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^-?\d+(\.\d{1,'.$scale.'})?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', $scale);
    }
}
