<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Raporu .xlsx dosyasına yazar: "Rapor" sayfası (başlık + satırlar; sayılar sayı, tarihler tarih hücresi) ve
 * "Özet" sayfası (rapor adı, tarih aralığı, filtreler, özet).
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
        $writer->addRow(Row::fromValues(array_map(fn (ReportColumn $c) => $c->label, $columns), $header));

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (ReportColumn $c) => $this->cell($c, $row[$c->key] ?? null, $styles), $columns)));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Özet');
        $writer->addRow(Row::fromValues([$report->title()], $header));
        $writer->addRow(Row::fromValues(['Tarih aralığı', $filters->rangeLabel()]));
        $writer->addRow(Row::fromValues(['Hazırlanma', now()->format('d.m.Y H:i')]));

        foreach ($report->summary($filters, $rows) as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }

        $writer->close();
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
            'boolean' => Cell::fromValue($value ? 'Evet' : 'Hayır'),
            default => Cell::fromValue((string) $value),
        };
    }
}
