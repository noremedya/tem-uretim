<?php

namespace App\Imports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * İçe aktarma şablonunu (.xlsx) yazar: "Nasıl doldurulur" sayfası ve her içe aktarma sayfası için başlık, açıklama
 * satırı ("#" ile başlar, okunmaz) ve verilen satırlar (boş şablonda yok; başlangıç dosyasında dolu).
 */
final class CatalogTemplate
{
    /** Sayı hücresi olarak yazılan sütunlar. */
    private const NUMERIC = ['critical_level', 'net', 'scrap_rate', 'gross', 'scrap_quantity', 'quantity', 'power_kw', 'speed_rpm', 'frequency_hz', 'pole_count', 'net_weight_kg'];

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows  sayfa anahtarı => satırlar (sütun anahtarıyla)
     * @param  list<string>  $guide  "Nasıl doldurulur" sayfasına eklenecek satırlar
     */
    public function write(string $path, array $rows = [], array $guide = []): void
    {
        $options = new Options;
        $options->setColumnWidth(24, ...range(1, 16));
        $writer = new Writer($options);
        $writer->openToFile($path);

        $bold = (new Style)->setFontBold();
        $note = (new Style)->setFontItalic()->setFontColor(Color::rgb(110, 110, 110))->setShouldWrapText(false);

        $writer->getCurrentSheet()->setName('Nasıl doldurulur');
        foreach ([
            'Ürün yapısı içe aktarma şablonu',
            '',
            '1. Sayfaları sırasıyla doldurun: Birimler, Ürünler, Motor modelleri, Reçeteler, Eş ürünler, Açılış stoğu. Kullanmadığınız sayfayı boş bırakın.',
            '2. Başlık satırını değiştirmeyin. "*" işaretli sütunlar zorunludur. "#" ile başlayan satırlar açıklamadır, okunmaz.',
            '3. Sayılar Türkçe yazılabilir: 12,5 veya 1.250,5. Tür adla (Hammadde) veya muhasebe koduyla (150.01) yazılabilir; depo kodla (HAM) veya adla.',
            '4. Dosyayı "İçe aktar" ekranından yükleyin: önce kontrol edilir, satır satır sonuç gösterilir; hata yoksa "Aktar" ile tek seferde kaydedilir.',
            '5. Sistemde zaten olan kodlar varsayılan olarak atlanır; "Mevcutları güncelle" seçilirse dosyada dolu olan alanlar güncellenir.',
            ...($guide === [] ? [] : ['', ...$guide]),
        ] as $line) {
            $writer->addRow(Row::fromValues([$line], $line === 'Ürün yapısı içe aktarma şablonu' ? $bold : null));
        }

        foreach (CatalogSheets::SHEETS as $key => $sheet) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheet['title']);
            $writer->addRow(Row::fromValues(array_values($sheet['columns']), $bold));
            $writer->addRow(Row::fromValues(['# '.$sheet['note']], $note));

            foreach ($rows[$key] ?? [] as $row) {
                $writer->addRow(new Row(array_map(
                    fn (string $column) => $this->cell($column, $row[$column] ?? null),
                    array_keys($sheet['columns']),
                )));
            }
        }

        $writer->close();
    }

    private function cell(string $column, mixed $value): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue(null);
        }

        if (in_array($column, self::NUMERIC, true) && is_numeric(str_replace(',', '.', (string) $value))) {
            return Cell::fromValue((float) str_replace(',', '.', (string) $value));
        }

        return Cell::fromValue((string) $value);
    }
}
