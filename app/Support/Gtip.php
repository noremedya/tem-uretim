<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;

/** GTİP (Gümrük Tarife İstatistik Pozisyonu): 12 hane. Girişte nokta/boşluk atılır; ekranda 8501.52.20.00.00 biçimi. */
final class Gtip
{
    public const INVALID_MESSAGE = 'GTİP 12 haneli olmalıdır (ör. 8501.52.20.00.00).';

    /** Boşsa null; geçersizse hata. */
    public static function normalize(mixed $value): ?string
    {
        $digits = preg_replace('/[\s.]/u', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (! preg_match('/^[0-9]{12}$/', $digits)) {
            throw new BusinessRuleException(self::INVALID_MESSAGE);
        }

        return $digits;
    }

    public static function isValid(mixed $value): bool
    {
        return preg_match('/^[0-9]{12}$/', preg_replace('/[\s.]/u', '', (string) $value)) === 1;
    }

    public static function format(?string $gtip): ?string
    {
        if ($gtip === null || strlen($gtip) !== 12) {
            return $gtip;
        }

        return implode('.', [substr($gtip, 0, 4), substr($gtip, 4, 2), substr($gtip, 6, 2), substr($gtip, 8, 2), substr($gtip, 10, 2)]);
    }
}
