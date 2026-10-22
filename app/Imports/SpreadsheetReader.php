<?php

namespace App\Imports;

use App\Exceptions\BusinessRuleException;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Şablon dosyasını okur: sayfa adına göre (CatalogSheets), ilk dolu satır başlık, "#" ile başlayan satırlar açıklama,
 * boş satırlar atlanır. Bilinmeyen sütunlar yok sayılır.
 */
final class SpreadsheetReader
{
    /** En fazla satır (sayfa başına). */
    public const MAX_ROWS = 5000;

    /**
     * @return array<string, list<array{row: int, values: array<string, mixed>}>> sayfa anahtarı => satırlar
     */
    public function read(string $path): array
    {
        $reader = new Reader;

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new BusinessRuleException('Dosya okunamadı; şablondaki gibi bir Excel (.xlsx) dosyası yükleyin.');
        }

        $titles = collect(CatalogSheets::SHEETS)->mapWithKeys(fn (array $sheet, string $key) => [CatalogSheets::normalize($sheet['title']) => $key]);
        $result = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $key = $titles[CatalogSheets::normalize($sheet->getName())] ?? null;

                if ($key === null) {
                    continue;
                }

                $result[$key] = $this->rows($sheet->getRowIterator(), $key);
            }
        } finally {
            $reader->close();
        }

        if ($result === []) {
            throw new BusinessRuleException('Dosyada şablon sayfalarından hiçbiri yok (Ürünler, Reçeteler…). Şablonu indirip onu doldurun.');
        }

        return $result;
    }

    /** @return list<array{row: int, values: array<string, mixed>}> */
    private function rows(iterable $rows, string $key): array
    {
        $definition = CatalogSheets::SHEETS[$key];
        $labels = collect($definition['columns'])->mapWithKeys(fn (string $label, string $column) => [CatalogSheets::normalize($label) => $column]);
        $map = null;
        $result = [];

        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($cell) => $this->value($cell->getValue()), $row->getCells());

            if (collect($cells)->every(fn ($value) => $value === null)) {
                continue;
            }

            if ($map === null) {
                $map = [];
                foreach ($cells as $position => $header) {
                    $column = $labels[CatalogSheets::normalize((string) $header)] ?? null;
                    if ($column !== null) {
                        $map[$position] = $column;
                    }
                }

                $missing = array_diff($definition['required'], $map);
                if ($missing !== []) {
                    throw new BusinessRuleException(sprintf('"%s" sayfasında zorunlu sütun yok: %s.', $definition['title'],
                        implode(', ', array_map(fn ($c) => rtrim($definition['columns'][$c], '*'), $missing))));
                }

                continue;
            }

            if (is_string($cells[0] ?? null) && str_starts_with($cells[0], '#')) {
                continue;
            }

            if (count($result) >= self::MAX_ROWS) {
                throw new BusinessRuleException(sprintf('"%s" sayfasında en fazla %d satır olabilir.', $definition['title'], self::MAX_ROWS));
            }

            $values = [];
            foreach ($map as $position => $column) {
                $values[$column] = $cells[$position] ?? null;
            }

            $result[] = ['row' => $index, 'values' => $values];
        }

        return $result;
    }

    private function value(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_string($value) => trim($value) === '' ? null : trim($value),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'Evet' : 'Hayır',
            default => $value,
        };
    }
}
