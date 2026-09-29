<?php

namespace App\Support;

/**
 * Vergi kimlik numarası (VKN, 10 hane) ve TC kimlik numarası (TCKN, 11 hane) kontrol hanesi doğrulaması.
 * Biçim (yalnızca rakam, 10–11 hane) Customer::TAX_NUMBER_PATTERN ve veritabanı check'iyle ayrıca denetlenir;
 * kontrol hanesi yalnızca form ve servis seviyesindedir.
 */
final class TaxNumber
{
    public const INVALID_MESSAGE = 'Geçersiz vergi/TC kimlik numarası, lütfen kontrol edin.';

    public static function isValid(?string $number): bool
    {
        return match (strlen((string) $number)) {
            10 => ctype_digit($number) && self::isValidVkn($number),
            11 => ctype_digit($number) && self::isValidTckn($number),
            default => false,
        };
    }

    /** VKN: son hane, ilk 9 haneden hesaplanan kontrol hanesine eşit olmalı. */
    public static function isValidVkn(string $vkn): bool
    {
        return (int) $vkn[9] === self::vknCheckDigit(substr($vkn, 0, 9));
    }

    /**
     * Gelir İdaresi VKN algoritması. Soldan i. hane (i = 0..8) için:
     *   t = (hane + 9 − i) mod 10;  p = t · 2^(9 − i) mod 9;  t ≠ 0 ve p = 0 ise p = 9
     * Kontrol hanesi = (10 − (Σp mod 10)) mod 10.
     */
    public static function vknCheckDigit(string $firstNine): int
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $t = ((int) $firstNine[$i] + 9 - $i) % 10;
            $p = ($t * (2 ** (9 - $i))) % 9;
            $sum += ($t !== 0 && $p === 0) ? 9 : $p;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * TCKN: ilk hane 0 olamaz;
     *   10. hane = ((1., 3., 5., 7., 9. hanelerin toplamı) · 7 − (2., 4., 6., 8. hanelerin toplamı)) mod 10
     *   11. hane = (ilk 10 hanenin toplamı) mod 10
     */
    public static function isValidTckn(string $tckn): bool
    {
        $d = array_map('intval', str_split($tckn));

        if ($d[0] === 0) {
            return false;
        }

        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        // PHP'de % negatif sonuç verebilir; pozitif moda çevrilir.
        $tenth = ((($odd * 7) - $even) % 10 + 10) % 10;
        $eleventh = array_sum(array_slice($d, 0, 10)) % 10;

        return $d[9] === $tenth && $d[10] === $eleventh;
    }
}
