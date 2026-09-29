<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Otomatik numara üretimi (sipariş no; Aşama 4'te iş emri no ve seri no).
 *
 * Numara config/numbering.php'deki şablondan üretilir. Sayaç kapsamı, şablonun sıra dışındaki kısmıdır;
 * "SIP-{YIL}-{SIRA:5}" için kapsam "SIP-2026-#" olduğundan sıra her yıl kendiliğinden sıfırlanır.
 *
 * Sayaç tek atomik sorguyla artırılır (INSERT ... ON CONFLICT DO UPDATE ... RETURNING). Satır kilidi
 * çağıranın transaction'ı bitene kadar tutulur: eşzamanlı çağrılar sırayla numara alır ve geri alınan
 * işlem numara tüketmez. Bu yüzden numarayı kullanan kayıtla aynı transaction içinde çağrılmalıdır.
 */
class NumberSequenceService
{
    private const SEQUENCE_TOKEN = '/\{SIRA:(\d{1,2})\}/';

    /**
     * @param  string  $name  config/numbering.php'deki anahtar (ör. "order").
     * @param  array<string, string>  $context  Şablondaki bağlam belirteçleri (ör. ['MODEL_KODU' => 'M100']).
     */
    public function next(string $name, ?CarbonInterface $date = null, array $context = []): string
    {
        $template = config("numbering.{$name}");

        if (! is_string($template) || $template === '') {
            throw new InvalidArgumentException("Numara şablonu tanımlı değil: {$name}");
        }

        $rendered = $this->render($template, $date ?? now(), $context);

        if (preg_match_all(self::SEQUENCE_TOKEN, $rendered, $matches) !== 1) {
            throw new InvalidArgumentException("Numara şablonunda tam olarak bir {SIRA:n} belirteci olmalı: {$template}");
        }

        if (preg_match('/\{[^}]*\}/', preg_replace(self::SEQUENCE_TOKEN, '', $rendered), $unknown)) {
            throw new InvalidArgumentException("Numara şablonunda bilinmeyen belirteç {$unknown[0]}: {$template}");
        }

        $scope = preg_replace(self::SEQUENCE_TOKEN, '#', $rendered);

        $value = DB::selectOne(<<<'SQL'
            INSERT INTO number_sequences (name, scope, last_value, created_at, updated_at)
            VALUES (?, ?, 1, now(), now())
            ON CONFLICT (name, scope) DO UPDATE
                SET last_value = number_sequences.last_value + 1, updated_at = now()
            RETURNING last_value
        SQL, [$name, $scope], useReadPdo: false)->last_value;

        $digits = (int) $matches[1][0];

        return preg_replace(self::SEQUENCE_TOKEN, str_pad((string) $value, $digits, '0', STR_PAD_LEFT), $rendered);
    }

    /** Sıra dışındaki belirteçleri doldurur. */
    private function render(string $template, CarbonInterface $date, array $context): string
    {
        $date = $date->copy()->setTimezone(config('app.timezone'));

        $replacements = [
            '{YIL}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{AA}' => $date->format('m'),
        ];

        foreach ($context as $key => $value) {
            $replacements['{'.mb_strtoupper($key).'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
