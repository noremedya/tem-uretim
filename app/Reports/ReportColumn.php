<?php

namespace App\Reports;

/**
 * Rapor sütunu. Tür, ekrandaki biçimi ve Excel hücre türünü belirler.
 * Türler: text, integer, decimal (3 ondalık), hours (1 ondalık), percent, date, datetime, boolean.
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function integer(string $key, string $label): self
    {
        return new self($key, $label, 'integer');
    }

    public static function decimal(string $key, string $label): self
    {
        return new self($key, $label, 'decimal');
    }

    public static function hours(string $key, string $label): self
    {
        return new self($key, $label, 'hours');
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent');
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, 'datetime');
    }

    public static function boolean(string $key, string $label): self
    {
        return new self($key, $label, 'boolean');
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, ['integer', 'decimal', 'hours', 'percent'], true);
    }
}
