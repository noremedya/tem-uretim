<?php

use App\Enums\PartType;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\BomEditor;
use App\Filament\Pages\CatalogImport;
use App\Imports\CatalogSheets;
use App\Imports\CatalogTemplate;
use App\Imports\StarterCatalog;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\Brand;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CatalogImportService;
use App\Services\StockService;
use App\Services\WarehouseService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(CatalogImportService::class);
});

/** Şablonla dosya yazar; yolu döner. */
function xlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    app(CatalogTemplate::class)->write($path, $rows);

    return $path;
}

function errorsOf($result): array
{
    return collect($result->sheets)->flatten(1)->where('status', 'error')->map(fn ($r) => "{$r['row']}: {$r['message']}")->values()->all();
}

it('başlangıç dosyası hatasız kontrol edilir, kontrol hiçbir şey kaydetmez; aktarınca ürün yapısı kurulur', function () {
    $path = xlsx(StarterCatalog::rows());
    $parts = Part::count();

    $checked = $this->service->check($path, $this->admin);

    expect(errorsOf($checked))->toBe([])
        ->and($checked->committed)->toBeFalse()
        ->and(Part::count())->toBe($parts)
        ->and($checked->counts(CatalogSheets::PARTS)['created'])->toBe(count(StarterCatalog::rows()[CatalogSheets::PARTS]));

    $imported = $this->service->import($path, $this->admin);

    expect($imported->committed)->toBeTrue()
        ->and(Unit::whereIn('name', ['Adet', 'Kg', 'Metre'])->count())->toBe(3)
        ->and(Part::where('code', 'HM-SAC-050-110')->sole()->typeLabel())->toBe('Hammadde › Silisli sac');

    $press = BomItem::query()->whereHas('parent', fn ($q) => $q->where('code', 'YM-STP-71-070'))->sole();
    expect($press)->quantity_per_unit->toBe('3.730')->scrap_quantity_per_unit->toBe('2.880')
        ->and($press->scrapPart->code)->toBe('HM-HURDA-SAC')
        ->and(BomCoProduct::query()->sole()->coPart->code)->toBe('YM-RTP-71-070')
        ->and(MotorModel::where('code', 'M71-037-4')->sole()->finishedPart->subtype->name)->toBe('Trifaze');

    // Aynı dosya tekrar: var olanlar atlanır.
    $again = $this->service->import($path, $this->admin);
    expect($again->counts(CatalogSheets::PARTS))->toBe(['skipped' => count(StarterCatalog::rows()[CatalogSheets::PARTS])]);
});

it('hatalar satır satır listelenir; tek hata varsa geçerli satırlar da kaydedilmez', function () {
    Unit::factory()->create(['name' => 'Kg']);
    $path = xlsx([
        CatalogSheets::PARTS => [
            ['code' => 'OK-1', 'name' => 'Geçerli', 'type' => 'Hammadde', 'unit' => 'kg'],
            ['code' => 'X-1', 'name' => 'Birimsiz', 'type' => 'Hammadde', 'unit' => 'Ton'],
            ['code' => 'X-2', 'name' => 'Türü yanlış', 'type' => 'Hurda', 'unit' => 'Kg'],
            ['code' => 'X-3', 'name' => 'Mamul', 'type' => '152', 'unit' => 'Kg'],
        ],
        CatalogSheets::BOM => [['parent' => 'YOK-1', 'component' => 'OK-1', 'net' => '1']],
    ]);

    $result = $this->service->import($path, $this->admin);

    expect($result->committed)->toBeFalse()
        ->and(errorsOf($result))->toBe([
            '4: Birim tanımlı değil: "Ton" (Birimler sayfasına ekleyin veya Birimler ekranından açın).',
            '5: Tür tanınmadı: "Hurda" (Hammadde, Komponent, Direkt sarf, Endirekt sarf, Yarı mamul).',
            '6: Mamul bu sayfaya yazılmaz; "Motor modelleri" sayfasına yazın (mamul kartı modelle açılır).',
            '3: Üst ürün bulunamadı: "YOK-1" (önce Ürünler/Motor modelleri sayfasında tanımlayın).',
        ])
        ->and(Part::where('code', 'OK-1')->exists())->toBeFalse();
});

it('var olan kod varsayılan atlanır; "güncelle" ile dolu alanlar güncellenir; marka otomatik açılır', function () {
    $kg = Unit::factory()->create(['name' => 'Kg']);
    $part = Part::factory()->create(['code' => 'HM-1', 'name' => 'Eski ad', 'type' => PartType::RawMaterial, 'unit_id' => $kg->id]);
    $path = xlsx([CatalogSheets::PARTS => [['code' => 'HM-1', 'name' => 'Yeni ad', 'type' => 'Hammadde', 'unit' => 'Kg', 'brand' => 'Demirsan', 'critical_level' => '1.250,5']]]);

    expect($this->service->import($path, $this->admin)->counts())->toBe(['skipped' => 1])
        ->and($part->fresh()->name)->toBe('Eski ad');

    $result = $this->service->import($path, $this->admin, update: true);

    expect($result->counts())->toBe(['updated' => 1])
        ->and($result->created)->toBe(['Marka: Demirsan'])
        ->and($part->fresh())->name->toBe('Yeni ad')
        ->and($part->fresh()->brand->name)->toBe('Demirsan')
        ->and($part->fresh('stockBalances')->criticalLevel())->toBe('1250.500')
        ->and(Brand::count())->toBe(1);
});

it('açılış stoğu depoda sayım düzeltmesi olarak girer; aynı sayım tekrar değişiklik yapmaz; mamul reddedilir', function () {
    $kg = Unit::factory()->create(['name' => 'Kg']);
    $part = Part::factory()->create(['code' => 'HM-2', 'type' => PartType::RawMaterial, 'unit_id' => $kg->id]);
    app(StockService::class)->stockIn($part, 10, $this->admin);
    $model = MotorModel::factory()->create(['code' => 'M-1']);
    $path = xlsx([CatalogSheets::OPENING => [
        ['code' => 'HM-2', 'warehouse' => 'HAM', 'quantity' => '120,5'],
        ['code' => 'HM-2', 'warehouse' => 'Üretim deposu', 'quantity' => '4'],
    ]]);

    expect($this->service->import($path, $this->admin)->committed)->toBeTrue()
        ->and((string) StockBalance::where('part_id', $part->id)->where('warehouse_id', Warehouse::where('code', 'HAM')->value('id'))->value('quantity'))->toBe('120.500')
        ->and((string) StockBalance::where('part_id', $part->id)->where('warehouse_id', Warehouse::where('code', 'URT')->value('id'))->value('quantity'))->toBe('4.000')
        ->and($this->service->import($path, $this->admin)->counts())->toBe(['unchanged' => 2]);

    $finished = xlsx([CatalogSheets::OPENING => [['code' => 'M-1', 'warehouse' => 'MML', 'quantity' => '3']]]);
    expect(errorsOf($this->service->check($finished, $this->admin)))->toBe(['3: Mamul (motor) açılış stoğu bu sayfayla girilemez.']);
});

it('dosyada şablon sayfası veya zorunlu sütun yoksa anlaşılır hata verir', function () {
    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Ürünler');
    $writer->addRow(Row::fromValues(['Kod', 'Ad']));
    $writer->close();

    expect(fn () => $this->service->check($path, $this->admin))->toThrow(BusinessRuleException::class, 'zorunlu sütun yok: Tür, Birim');
});

it('reçeteye Excel satırları yapıştırılır; hatalı satır varsa hiçbiri eklenmez', function () {
    $kg = Unit::factory()->create(['name' => 'Kg']);
    $parent = Part::factory()->create(['code' => 'YM-1', 'type' => PartType::SemiFinished]);
    Part::factory()->create(['code' => 'HM-SAC', 'type' => PartType::RawMaterial, 'unit_id' => $kg->id]);
    Part::factory()->create(['code' => 'HM-ALU', 'type' => PartType::RawMaterial, 'unit_id' => $kg->id]);

    $bad = $this->service->pasteBom($parent, "HM-SAC\t\t\t6,61\t2,88\nYOK\t1", $this->admin);
    expect($bad->committed)->toBeFalse()->and($parent->bomItems()->count())->toBe(0);

    $good = $this->service->pasteBom($parent, "HM-SAC\t\t\t6,61\t2,88\nHM-ALU\t0,35\t3", $this->admin);
    expect($good->committed)->toBeTrue()
        ->and($parent->bomItems()->count())->toBe(2)
        ->and($parent->bomItems()->whereHas('component', fn ($q) => $q->where('code', 'HM-ALU'))->sole()->scrap_rate)->toBe('3.00');

    $this->actingAs($this->admin);
    Livewire::withQueryParams(['part' => $parent->id])->test(BomEditor::class)
        ->callAction(TestAction::make('paste')->table(), data: ['text' => "HM-SAC\t2", 'update' => true])
        ->assertNotified();

    expect($parent->bomItems()->whereHas('component', fn ($q) => $q->where('code', 'HM-SAC'))->sole()->quantity_per_unit)->toBe('2.000');
});

it('İçe aktar ekranı: yalnızca yönetici; dosya kontrol edilir ve aktarılır; şablonlar indirilir', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);
    Unit::factory()->create(['name' => 'Kg']);
    $upload = UploadedFile::fake()->createWithContent('urunler.xlsx', file_get_contents(xlsx([CatalogSheets::PARTS => [['code' => 'HM-9', 'name' => 'Deneme', 'type' => 'Hammadde', 'unit' => 'Kg']]])));

    Livewire::test(CatalogImport::class)
        ->assertActionExists('template')
        ->callAction('starter')
        ->assertFileDownloaded('urun-yapisi-baslangic.xlsx')
        ->set('data.file', $upload)
        ->callAction(TestAction::make('check')->schemaComponent('import-actions', schema: 'content'))
        ->assertNotified('Dosya kontrol edildi, hata yok')
        ->assertSee('Eklenecek')
        ->tap(fn () => expect(Part::where('code', 'HM-9')->exists())->toBeFalse())
        ->callAction(TestAction::make('import')->schemaComponent('import-actions', schema: 'content'))
        ->assertNotified('İçe aktarma tamamlandı')
        ->assertSee('Eklendi');

    expect(Part::where('code', 'HM-9')->exists())->toBeTrue();

    foreach (['operator', 'warehouse'] as $role) {
        $this->actingAs(User::factory()->{$role}()->create());
        $this->get(CatalogImport::getUrl())->assertForbidden();
    }

    $this->actingAs(User::factory()->withRoles(Role::Accounting)->create());
    $this->get(CatalogImport::getUrl())->assertForbidden();
});

it('alüminyum parça listesi 28 kart olarak ALU deposuna, ağırlığıyla reçeteye girer; klemens kutusu komponent kalır', function () {
    $this->service->import(xlsx(StarterCatalog::rows()), $this->admin);

    $parts = Part::query()->where('code', 'like', 'YM-ALU-%')->orderBy('code')->get();
    $warehouses = app(WarehouseService::class);
    $line = fn (string $code) => BomItem::query()->with('component')->whereHas('parent', fn ($q) => $q->where('code', $code))->sole();

    expect($parts)->toHaveCount(28)
        ->and($parts->map(fn (Part $part) => $warehouses->defaultFor($part)->code)->unique()->values()->all())->toBe(['ALU'])
        ->and($parts[1]->name)->toBe('90-B-3- Kapak')
        ->and($parts[1]->description)->toBe('Kalıp 2 göz · Etial 160 · Ağırlık 910 g')
        ->and($parts[12]->name)->toBe('KL. 15- FLAŞ')
        ->and($parts[17]->name)->toBe('90 TİP LONG Gövde')
        ->and($line('YM-ALU-02')->quantity_per_unit)->toBe('0.910')
        ->and($line('YM-ALU-02')->component->code)->toBe('HM-ALU-ETIAL-160')
        ->and($line('YM-ALU-05')->component->code)->toBe('HM-ALU-ETIAL-160')
        ->and($line('YM-ALU-18')->component->code)->toBe('HM-ALU-ETIAL-141')
        ->and($line('YM-ALU-26')->quantity_per_unit)->toBe('1.420')
        ->and($line('YM-ALU-28')->component->code)->toBe('HM-ALU-ETIAL-170')
        ->and(Part::query()->where('code', 'like', 'KOM-KLM-%')->pluck('type')->unique()->values()->all())->toBe([PartType::Component]);
});

it('ürün deposu açıkken başlangıç dosyası reçetedeki her ürüne depo açar, açılış stoğu ürün deposuna yazılır', function () {
    config(['stock.part_warehouses' => true]);
    $rows = StarterCatalog::rows();
    $rows[CatalogSheets::OPENING] = [['code' => 'HM-ALU-ETIAL-160', 'warehouse' => 'HAM', 'quantity' => '500']];

    $this->service->import(xlsx($rows), $this->admin);

    $alloy = Part::where('code', 'HM-ALU-ETIAL-160')->sole();

    expect(Warehouse::query()->whereNotNull('part_id')->count())->toBeGreaterThan(30)
        ->and(Warehouse::query()->where('code', 'YM-ALU-02')->exists())->toBeTrue()
        ->and(StockBalance::query()->where('part_id', $alloy->id)->where('quantity', '>', 0)->sole()->warehouse->code)->toBe('HM-ALU-ETIAL-160')
        ->and(Part::where('code', 'KOM-KLM-TRF-56-71')->sole()->default_warehouse_id)->toBeNull();
});
