<?php

namespace App\Imports;

/**
 * İçe aktarma sonucu: sayfa başına satır durumları (created, updated, skipped, unchanged, error) ve açılan tanımlar.
 */
final class ImportResult
{
    public const LABELS = [
        'created' => 'Eklenecek', 'updated' => 'Güncellenecek', 'skipped' => 'Atlanacak (zaten var)',
        'unchanged' => 'Değişiklik yok', 'error' => 'Hata',
    ];

    public const LABELS_DONE = [
        'created' => 'Eklendi', 'updated' => 'Güncellendi', 'skipped' => 'Atlandı (zaten var)',
        'unchanged' => 'Değişiklik yok', 'error' => 'Hata',
    ];

    public function label(string $status): string
    {
        return ($this->committed ? self::LABELS_DONE : self::LABELS)[$status] ?? $status;
    }

    /** @var array<string, list<array{row: int, key: string, status: string, message: ?string}>> */
    public array $sheets = [];

    /** @var list<string> Otomatik açılan tanımlar (marka, alt tür). */
    public array $created = [];

    public bool $committed = false;

    public function add(string $sheet, int $row, string $key, string $status, ?string $message = null): void
    {
        $this->sheets[$sheet][] = ['row' => $row, 'key' => $key, 'status' => $status, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errorCount() > 0;
    }

    public function errorCount(): int
    {
        return collect($this->sheets)->flatten(1)->where('status', 'error')->count();
    }

    /** @return array<string, int> durum => sayı */
    public function counts(?string $sheet = null): array
    {
        $rows = $sheet === null ? collect($this->sheets)->flatten(1) : collect($this->sheets[$sheet] ?? []);

        return $rows->countBy('status')->all();
    }

    public function toArray(): array
    {
        return ['sheets' => $this->sheets, 'created' => $this->created, 'committed' => $this->committed];
    }

    public static function fromArray(array $data): self
    {
        $result = new self;
        $result->sheets = $data['sheets'] ?? [];
        $result->created = $data['created'] ?? [];
        $result->committed = $data['committed'] ?? false;

        return $result;
    }
}
