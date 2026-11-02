<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Raporu .xlsx dosyasına yazar: "Rapor" sayfası (başlık + satırlar; sayılar sayı, tarihler tarih hücresi) ve
 * "Özet" sayfası (rapor adı, tarih aralığı, filtreler, özet). Metinler her zaman metin hücresidir: Cell::fromValue "="
 * ile başlayan değeri formül yapar, ürün adı gibi kullanıcı verisi Excel'de çalıştırılmamalıdır.
 */
final class ReportExcelWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function write(Report $report, ReportFilters $filters, array $rows, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $header = (new Style)->setFontBold();
        $styles = [
            'integer' => (new Style)->setFormat('#,##0'),
            'decimal' => (new Style)->setFormat('#,##0.###'),
            'hours' => (new Style)->setFormat('#,##0.0'),
            'percent' => (new Style)->setFormat('0.0'),
            'date' => (new Style)->setFormat('dd.mm.yyyy'),
            'datetime' => (new Style)->setFormat('dd.mm.yyyy hh:mm'),
        ];

        $writer->getCurrentSheet()->setName('Rapor');
        $columns = $report->columns();
        $writer->addRow(new Row(array_map(fn (ReportColumn $c) => new StringCell($c->label, $header), $columns)));

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (ReportColumn $c) => $this->cell($c, $row[$c->key] ?? null, $styles), $columns)));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Özet');
        $writer->addRow(new Row([new StringCell($report->title(), $header)]));
        $writer->addRow($this->textRow('Tarih aralığı', $filters->rangeLabel()));
        $writer->addRow($this->textRow('Hazırlanma', now()->format('d.m.Y H:i')));

        foreach ($report->summary($filters, $rows) as $label => $value) {
            $writer->addRow($this->textRow($label, $value));
        }

        $writer->close();
    }

    private function textRow(string $label, string $value): Row
    {
        return new Row([new StringCell($label, null), new StringCell($value, null)]);
    }

    /** @param array<string, Style> $styles */
    private function cell(ReportColumn $column, mixed $value, array $styles): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue(null);
        }

        return match ($column->type) {
            'integer' => Cell::fromValue((int) $value, $styles['integer']),
            'decimal', 'hours', 'percent' => Cell::fromValue((float) $value, $styles[$column->type]),
            'date', 'datetime' => Cell::fromValue(CarbonImmutable::parse($value), $styles[$column->type]),
            'boolean' => new StringCell($value ? 'Evet' : 'Hayır', null),
            default => new StringCell((string) $value, null),
        };
    }
}
