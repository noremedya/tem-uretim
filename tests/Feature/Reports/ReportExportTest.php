<?php

use App\Filament\Pages\Reports\StockMovementListPage;
use App\Jobs\ExportReportJob;
use App\Models\Part;
use App\Models\ReportExport;
use App\Models\User;
use App\Reports\ReportExcelWriter;
use App\Reports\ReportFilters;
use App\Reports\StockMovementListReport;
use App\Services\ReportExportService;
use App\Services\StockService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->part = Part::factory()->create(['code' => 'HM-TEL']);
    app(StockService::class)->stockIn($this->part, '12,5', $this->admin, 'Açılış');
    app(StockService::class)->stockOut($this->part, '2', $this->admin, 'Kullanım');
    $this->filters = ReportFilters::fromArray(['preset' => 'custom', 'from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]);
});

/** @return list<list<mixed>> sayfa adı => satırlar */
function readXlsx(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }

    $reader->close();

    return $sheets;
}

it('formül gibi başlayan ad ve açıklama Excel\'de metin kalır, hücreye formül yazılmaz', function () {
    $name = '=HYPERLINK("http://ornek.test","tikla")';
    $part = Part::factory()->create(['code' => 'HM-FORMUL', 'name' => $name]);
    app(StockService::class)->stockIn($part, '1', $this->admin, $name);

    $report = app(StockMovementListReport::class);
    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    app(ReportExcelWriter::class)->write($report, $this->filters, $report->rows($this->filters), $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $content = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/worksheets/sheet2.xml').$zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    expect($content)->not->toContain('<f>')
        ->and(collect(readXlsx($path)['Rapor'])->flatten()->all())->toContain($name);
});

it('Excel dosyası Türkçe başlıklar, sayı hücreleri ve özet sayfası içerir', function () {
    $report = app(StockMovementListReport::class);
    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';

    app(ReportExcelWriter::class)->write($report, $this->filters, $report->rows($this->filters), $path);
    $sheets = readXlsx($path);

    expect(array_keys($sheets))->toBe(['Rapor', 'Özet'])
        ->and($sheets['Rapor'][0])->toContain('Tarih', 'Kod', 'Depo', 'Hareket', 'Miktar')
        ->and($sheets['Rapor'][1][1])->toBe('HM-TEL')
        ->and($sheets['Rapor'][1][5])->toBe(12.5)
        ->and($sheets['Rapor'][2][5])->toEqual(-2)
        ->and($sheets['Rapor'][1][0])->toBeInstanceOf(DateTimeInterface::class)
        ->and($sheets['Özet'][0][0])->toBe('Stok hareket listesi')
        ->and($sheets['Özet'][1][1])->toBe($this->filters->rangeLabel());

    @unlink($path);
});

it('küçük rapor doğrudan iner', function () {
    $this->actingAs($this->admin);

    Livewire::test(StockMovementListPage::class)
        ->set('filters.preset', 'today')
        ->callAction('export')
        ->assertFileDownloaded('stok_hareketleri_'.today()->format('Ymd').'_'.today()->format('Ymd').'.xlsx');
});

it('eşiği aşan rapor kuyruğa alınır; iş dosyayı hazırlar ve bildirim gönderir; yalnızca hazırlayan indirir', function () {
    Storage::fake('local');
    // Yalnızca rapor işi taklit edilir; Filament'in (kuyruklu) veritabanı bildirimi gerçekten yazılsın.
    Queue::fake([ExportReportJob::class]);
    $this->actingAs($this->admin);

    // Eşiği aşan satır sayısı (ekrandaki ilk kontrol için); iş gerçek satırlarla çalışır.
    $report = Mockery::mock(StockMovementListReport::class)->makePartial();
    $report->shouldReceive('rows')->andReturn(array_fill(0, ReportExportService::QUEUE_THRESHOLD + 1, ['code' => 'X']));

    expect(app(ReportExportService::class)->export($report, $this->filters, $this->admin))->toBeNull();
    Queue::assertPushed(ExportReportJob::class);

    $export = ReportExport::sole();
    expect($export)->status->toBe('pending')->report_key->toBe('stok_hareketleri');

    // İşi çalıştır (gerçek rapor satırlarıyla).
    (new ExportReportJob($export->id))->handle(app(ReportExportService::class));

    $export->refresh();
    expect($export)->status->toBe('ready')->row_count->toBe(2);
    Storage::disk('local')->assertExists($export->path);

    $notification = $this->admin->notifications()->sole();
    expect($notification->data['title'])->toBe('Stok hareket listesi hazır');

    $this->get(route('report-exports.download', $export))->assertOk()->assertDownload($export->file_name);

    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('report-exports.download', $export))->assertForbidden();
});

it('7 günden eski dışa aktarımlar silinir', function () {
    Storage::fake('local');
    $export = new ReportExport;
    $export->forceFill(['user_id' => $this->admin->id, 'report_key' => 'stok_hareketleri', 'filters' => [], 'file_name' => 'a.xlsx', 'path' => 'report-exports/a.xlsx', 'status' => 'ready'])->save();
    Storage::disk('local')->put('report-exports/a.xlsx', 'x');
    $this->travel(8)->days();

    $this->artisan('reports:prune-exports')->expectsOutputToContain('1 eski rapor dosyası silindi')->assertSuccessful();

    expect(ReportExport::count())->toBe(0);
    Storage::disk('local')->assertMissing('report-exports/a.xlsx');
});

it('yetkisiz kullanıcı rapor dışa aktaramaz', function () {
    $operator = User::factory()->operator()->create();

    expect(fn () => app(ReportExportService::class)->export(app(StockMovementListReport::class), $this->filters, $operator))
        ->toThrow(HttpException::class);
});
