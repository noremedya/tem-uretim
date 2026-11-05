<?php

namespace Database\Seeders;

use App\Enums\CashAccountKind;
use App\Enums\Currency;
use App\Enums\ExportType;
use App\Enums\Incoterm;
use App\Enums\InvoiceType;
use App\Enums\MotorPhase;
use App\Enums\MotorUnitStatus;
use App\Enums\PackageType;
use App\Enums\PartType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransportMode;
use App\Enums\WorkOrderStatus;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MotorModel;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BomService;
use App\Services\BrandService;
use App\Services\CashAccountService;
use App\Services\ChequeService;
use App\Services\CustomerService;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\MotorDesignService;
use App\Services\MotorModelService;
use App\Services\OrderService;
use App\Services\PartService;
use App\Services\PaymentService;
use App\Services\PriceService;
use App\Services\ProductionService;
use App\Services\StockService;
use App\Services\UserService;
use App\Services\WarehouseService;
use App\Services\WorkOrderService;
use App\Support\Quantity;
use App\Support\TaxNumber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Yalnızca geliştirme ve deneme için örnek veri (CLAUDE.md bölüm 9). Üretimde çalışmaz.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Kayıtlar doğrudan tabloya yazılmaz; uygulamanın servisleriyle, ilgili kullanıcının oturumu ve o anın tarihi
 * ayarlanarak oluşturulur. Böylece bakiyeler, hareketler, cari, numaralar ve denetim izi kurallara uygundur.
 *
 * Kurgu: 120 gün önce sistem geçişi (açılış sayımı, devir bakiyeleri) → son $weeks haftada haftalık sipariş,
 * yarı mamul ve motor iş emirleri, sevk, satış ve alış faturaları, tahsilat ve ödemeler. Şu andan sonraya
 * düşen adımlar yapılmaz; son haftanın işleri planlanmış veya üretimde kalır.
 *
 * Demo kullanıcılarının şifresi: demo1234 (yonetici, operator1, operator2, depo, muhasebe).
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'demo1234';

    /** Bu kart varsa demo veri yüklenmiş sayılır ve seeder bir şey yapmaz. */
    public const MARKER_PART = 'HM-SAC-050';

    /** Üretim temposunun süreceği hafta sayısı (testler daha kısa çalıştırır). */
    public int $weeks = 7;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Part> */
    private array $parts = [];

    /** @var array<string, string> parça kodu => tedarikçi anahtarı */
    private array $partSupplier = [];

    /** @var array<string, string> parça kodu => alış birim fiyatı */
    private array $purchasePrice = [];

    /** @var array<string, MotorModel> çerçeve (71, 80, 90S) => model */
    private array $models = [];

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, CashAccount> */
    private array $accounts = [];

    /** @var array<string, array<string, list<int>>> tedarikçi => parça kodu => faturalanmamış stok girişi id'leri */
    private array $pendingReceipts = [];

    /** @var list<array{at: CarbonImmutable, seq: int, run: Closure}> */
    private array $events = [];

    private int $sequence = 0;

    private CarbonImmutable $realNow;

    private int $random = 20261018;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder üretim ortamında çalıştırılamaz.');
        }

        // Demo sunucusunda (internete açık) bilinen şifre kullanılmaz.
        if (app()->environment(config('demo.environment')) && mb_strlen((string) config('demo.password')) < 10) {
            throw new RuntimeException('Demo sunucusunda .env içine en az 10 karakterlik DEMO_PASSWORD yazın.');
        }

        if (Part::query()->where('code', self::MARKER_PART)->exists()) {
            $this->command?->warn('Demo veri zaten yüklü; bir şey yapılmadı.');

            return;
        }

        $this->call([RolesAndPermissionsSeeder::class, UnitSeeder::class]);

        $frozenNow = CarbonImmutable::getTestNow();
        $this->realNow = CarbonImmutable::now();

        try {
            DB::transaction(function (): void {
                $start = $this->realNow->subDays(120)->setTime(8, 0);

                $this->at($start, fn () => $this->createMasterData($start));
                $this->scheduleWeeks();
                $this->runEvents();
            });
        } finally {
            // Çağıran saati dondurmuşsa (test) o saate dönülür.
            Carbon::setTestNow($frozenNow);
            CarbonImmutable::setTestNow($frozenNow);
            Auth::forgetUser();
        }

        $this->command?->info('Demo veri yüklendi. Kullanıcılar: yonetici, operator1, operator2, depo, muhasebe (şifre: '
            .(app()->environment(config('demo.environment')) ? '.env içindeki DEMO_PASSWORD' : self::PASSWORD).')');
    }

    // --- Ana veriler (120 gün önce, sistem geçişi) ----------------------------------------------------------

    /** Tasarım (deneme reçetesi): stoktan düşmez; bir bileşenin henüz ürün kartı yok. */
    private function createDesign(): void
    {
        $service = app(MotorDesignService::class);
        $design = $service->create([
            'code' => 'TSR-71-IE3', 'name' => '71 tip IE3 deneme', 'description' => 'Daha uzun paket ve yeni fanla verim denemesi.',
        ]);

        $service->addItem($design, ['part_id' => $this->parts['KOM-RUL-6202']->getKey(), 'quantity' => '2']);
        $service->addItem($design, ['part_id' => $this->parts['KOM-KLEMENSBLOK']->getKey(), 'quantity' => '1']);
        $service->addItem($design, ['part_id' => $this->parts['DS-VERNIK']->getKey(), 'quantity' => '0,25', 'notes' => 'Daldırma süresi uzatıldı']);
        $service->addItem($design, ['description' => 'Yeni tip fan (numune)', 'unit_id' => $this->units['Adet']->getKey(), 'quantity' => '1', 'notes' => 'Tedarikçi numunesi']);
    }

    private function createMasterData(CarbonImmutable $start): void
    {
        $this->createUsers();

        $this->units = Unit::query()->get()->keyBy('name')->all();

        $this->as('yonetici', function (): void {
            $this->createParts();
            $this->createModelsAndBoms();
            $this->createCustomers();
            $this->createDesign();
        });
        $this->as('yonetici', fn () => $this->attachImages());
        $this->as('yonetici', fn () => $this->assignBrands());
        $this->as('muhasebe', fn () => $this->createRatesAndPrices($start));
        $this->as('yonetici', fn () => $this->createAccountsAndOpenings($start));

        // Açılış sayımı: Mikro'dan devralınan fiili stok.
        $this->as('depo', function (): void {
            foreach ($this->openingStock() as $code => $quantity) {
                app(StockService::class)->adjustCount($this->parts[$code], $quantity, $this->users['depo'], 'Açılış sayımı (sistem geçişi)');
            }
        });
    }

    private function createUsers(): void
    {
        $service = app(UserService::class);

        foreach ([
            'yonetici' => ['Selim Aydın', [Role::Admin]],
            'operator1' => ['Hasan Demir', [Role::Operator]],
            'operator2' => ['Ayşe Yılmaz', [Role::Operator]],
            'operator3' => ['Kemal Öztürk', [Role::Operator]],
            'depo' => ['Fatma Çelik', [Role::Warehouse]],
            'muhasebe' => ['Elif Şahin', [Role::Accounting]],
        ] as $username => [$name, $roles]) {
            $this->users[$username] = User::query()->where('username', $username)->first()
                ?? $service->create(['username' => $username, 'name' => $name, 'password' => $this->password(), 'roles' => $roles]);
        }

        // Ayrılmış bir operatör: pasif kullanıcı örneği.
        $this->as('yonetici', fn () => $service->deactivate($this->users['operator3'], $this->users['yonetici']));
    }

    /** Kurulumdaki örnek alt türlerden, kod önekine göre (yoksa alt tür yok). */
    private function subtypeFor(string $code, PartType $type): ?int
    {
        $name = match (true) {
            str_starts_with($code, 'HM-SAC') => 'Silisli sac',
            str_starts_with($code, 'HM-BAKIR') => 'Bakır tel',
            str_starts_with($code, 'HM-ALU') => 'Alüminyum',
            str_starts_with($code, 'HM-MIL') => 'Mil çeliği',
            str_starts_with($code, 'KOM-RUL') => 'Rulman',
            str_starts_with($code, 'KOM-TAS') => 'Pervane koruma tası',
            $code === 'KOM-KUTUKAPAK' => 'Klemens kutusu kapağı',
            str_starts_with($code, 'KOM-CONTA') => 'Conta',
            str_starts_with($code, 'KOM-VRING') => 'V-ring',
            $code === 'KOM-KLEMENSBLOK' => 'Klemens',
            str_starts_with($code, 'KOM-RAKOR') => 'Kablo rakoru',
            str_starts_with($code, 'KOM-KOND-D') => 'Daimi kondansatör',
            str_starts_with($code, 'KOM-KOND-S') => 'Start kondansatörü',
            $code === 'KOM-MERKEZKAC' => 'Merkezkaç anahtarı',
            str_starts_with($code, 'KOM-KLEMENS') => 'Klemens kutusu',
            str_starts_with($code, 'KOM-FAN') => 'Pervane',
            str_starts_with($code, 'DS-VERNIK') => 'Vernik',
            str_starts_with($code, 'DS-CIVATA') => 'Bağlantı elemanı',
            $code === 'YM-STATOR' => 'Bobinajlı stator',
            $code === 'YM-ROTOR' => 'Enjekte rotor',
            $code === 'YM-STP' => 'Stator paketi',
            $code === 'YM-RTP' => 'Rotor paketi',
            $code === 'YM-MILROTOR' => 'Mil+rotor',
            $code === 'YM-MIL' => 'İşlenmiş mil',
            $code === 'YM-GOVDE' => 'Gövde',
            $code === 'YM-KAPAK' => 'Kapak',
            $code === 'MAMUL' => 'Trifaze',
            default => null,
        };

        return $name === null ? null : PartSubtype::query()->where('type', $type->value)->where('name', $name)->value('id');
    }

    /** Demo sunucusunda DEMO_PASSWORD, geliştirmede sabit şifre. */
    private function password(): string
    {
        return app()->environment(config('demo.environment')) ? (string) config('demo.password') : self::PASSWORD;
    }

    private function createParts(): void
    {
        // kod => [ad, tür, birim, kritik seviye, tedarikçi, alış fiyatı, barkod]
        $parts = [
            'HM-SAC-050' => ['Silisli sac 0,50 mm', PartType::RawMaterial, 'Kg', '600', 'demirsan', '62', null],
            'HM-BAKIR-063' => ['Emaye bakır tel 0,63 mm', PartType::RawMaterial, 'Kg', '120', 'kablotel', '465', null],
            'HM-ALU-ETIAL' => ['Alüminyum döküm alaşımı (Etial 150)', PartType::RawMaterial, 'Kg', '250', 'kuzeyalu', '155', null],
            // Mil hammaddesi çap bazında kg (71 tip Ø20, 80 tip Ø25, 90 tip Ø30).
            'HM-MIL-C45-20' => ['Mil çeliği C45 Ø20', PartType::RawMaterial, 'Kg', '60', 'demirsan', '47', null],
            'HM-MIL-C45-25' => ['Mil çeliği C45 Ø25', PartType::RawMaterial, 'Kg', '60', 'demirsan', '48', null],
            'HM-MIL-C45-30' => ['Mil çeliği C45 Ø30', PartType::RawMaterial, 'Kg', '60', 'demirsan', '50', null],
            // Pres firesi: tamamlamada sac firesi bu karta girer (hurda satışı için).
            'HM-HURDA-SAC' => ['Sac hurdası (pres firesi)', PartType::RawMaterial, 'Kg', '0', 'demirsan', '0', null],
            'KOM-RUL-6202' => ['Rulman 6202 2RS', PartType::Component, 'Adet', '120', 'rulman', '38', '8690000062027'],
            'KOM-RUL-6204' => ['Rulman 6204 2RS', PartType::Component, 'Adet', '120', 'rulman', '52', '8690000062041'],
            'KOM-RUL-6205' => ['Rulman 6205 2RS', PartType::Component, 'Adet', '80', 'rulman', '64', '8690000062058'],
            'KOM-KLEMENS' => ['Klemens kutusu (6 uçlu)', PartType::Component, 'Adet', '100', 'rulman', '45', '8690000010011'],
            'KOM-KLEMENS-ESKI' => ['Klemens kutusu eski tip (71)', PartType::Component, 'Adet', '0', 'rulman', '40', null],
            'KOM-FAN-71' => ['Soğutma fanı 71 tip', PartType::Component, 'Adet', '50', 'rulman', '18', '8690000071010'],
            'KOM-FAN-80' => ['Soğutma fanı 80 tip', PartType::Component, 'Adet', '50', 'rulman', '24', '8690000080012'],
            'KOM-FAN-90' => ['Soğutma fanı 90 tip', PartType::Component, 'Adet', '40', 'rulman', '31', '8690000090014'],
            // B3 montaj parça listesindeki diğer bileşenler ("Motor oluştur" ekranı) ve monofaze parçaları.
            'KOM-KUTUKAPAK' => ['Uç bağlantı kutu kapağı (alüminyum)', PartType::Component, 'Adet', '50', 'kuzeyalu', '14', null],
            'KOM-CONTA-KUTU' => ['Conta, klemens kutusu altı', PartType::Component, 'Adet', '100', 'rulman', '3', null],
            'KOM-CONTA-KAPAK' => ['Conta, kutu kapağı', PartType::Component, 'Adet', '100', 'rulman', '2,50', null],
            'KOM-VRING-20' => ['V-ring toz contası Ø20', PartType::Component, 'Adet', '100', 'rulman', '4', null],
            'KOM-VRING-25' => ['V-ring toz contası Ø25', PartType::Component, 'Adet', '100', 'rulman', '5', null],
            'KOM-KLEMENSBLOK' => ['Klemens bloğu 6 uçlu (köprü, somun ve pullar dahil)', PartType::Component, 'Adet', '100', 'rulman', '22', null],
            'KOM-RAKOR-M20' => ['Kablo giriş rakoru M20', PartType::Component, 'Adet', '100', 'kablotel', '6', null],
            'KOM-KOND-D-12' => ['Daimi kondansatör 12 µF 450 V', PartType::Component, 'Adet', '40', 'kablotel', '48', null],
            'KOM-KOND-S-60' => ['Start kondansatörü 60 µF 250 V', PartType::Component, 'Adet', '40', 'kablotel', '55', null],
            'KOM-MERKEZKAC' => ['Merkezkaç anahtarı', PartType::Component, 'Adet', '30', 'rulman', '75', null],
            'DS-VERNIK' => ['Emprenye verniği', PartType::DirectConsumable, 'Kg', '40', 'rulman', '310', null],
            'DS-IZOLASYON' => ['Oluk izolasyon kağıdı', PartType::DirectConsumable, 'Metre', '200', 'kablotel', '22', null],
            'KOM-TAS-71' => ['Pervane muhafaza tası 71 tip', PartType::Component, 'Adet', '50', 'kuzeyalu', '35', '8690000071027'],
            'KOM-TAS-80' => ['Pervane muhafaza tası 80 tip', PartType::Component, 'Adet', '50', 'kuzeyalu', '42', '8690000080029'],
            'KOM-TAS-90' => ['Pervane muhafaza tası 90 tip', PartType::Component, 'Adet', '40', 'kuzeyalu', '55', '8690000090021'],
            'DS-CIVATA-M5' => ['Civata M5x16 (gövde/kapak)', PartType::DirectConsumable, 'Adet', '1000', 'rulman', '1,20', null],
            'DS-ETIKET' => ['Motor etiketi (alüminyum)', PartType::DirectConsumable, 'Adet', '150', 'rulman', '2,50', null],
            'ES-ELDIVEN' => ['İş eldiveni', PartType::IndirectConsumable, 'Adet', '20', 'rulman', '30', null],
            'ES-KESMEYAGI' => ['Kesme yağı', PartType::IndirectConsumable, 'Litre', '20', 'rulman', '140', null],
            'ES-ZIMPARA' => ['Zımpara kağıdı P120', PartType::IndirectConsumable, 'Adet', '0', 'rulman', '12', null],
        ];

        $service = app(PartService::class);

        foreach ($parts as $code => [$name, $type, $unit, $critical, $supplier, $price, $barcode]) {
            $this->parts[$code] = $service->create([
                'code' => $code, 'name' => $name, 'type' => $type, 'unit_id' => $this->units[$unit]->getKey(),
                'critical_level' => $critical, 'barcode' => $barcode, 'subtype_id' => $this->subtypeFor($code, $type),
            ]);
            $this->partSupplier[$code] = $supplier;
            $this->purchasePrice[$code] = $price;
        }
    }

    /** @return array<string, string> */
    private function openingStock(): array
    {
        return [
            'HM-SAC-050' => '1800', 'HM-BAKIR-063' => '420', 'HM-ALU-ETIAL' => '900', 'HM-MIL-C45-20' => '150', 'HM-MIL-C45-25' => '150', 'HM-MIL-C45-30' => '150',
            'KOM-RUL-6202' => '300', 'KOM-RUL-6204' => '260', 'KOM-RUL-6205' => '160', 'KOM-KLEMENS' => '240',
            'KOM-KLEMENS-ESKI' => '35', 'KOM-FAN-71' => '120', 'KOM-FAN-80' => '110', 'KOM-FAN-90' => '70',
            'KOM-KUTUKAPAK' => '200', 'KOM-CONTA-KUTU' => '400', 'KOM-CONTA-KAPAK' => '400', 'KOM-VRING-20' => '300', 'KOM-VRING-25' => '300',
            'KOM-KLEMENSBLOK' => '250', 'KOM-RAKOR-M20' => '300', 'KOM-KOND-D-12' => '80', 'KOM-KOND-S-60' => '60', 'KOM-MERKEZKAC' => '50',
            'DS-VERNIK' => '90', 'DS-IZOLASYON' => '600', 'KOM-TAS-71' => '110', 'KOM-TAS-80' => '100',
            'KOM-TAS-90' => '60', 'DS-CIVATA-M5' => '4000', 'DS-ETIKET' => '500',
            'ES-ELDIVEN' => '120', 'ES-KESMEYAGI' => '60', 'ES-ZIMPARA' => '200',
        ];
    }

    private function createModelsAndBoms(): void
    {
        $models = app(MotorModelService::class);
        $parts = app(PartService::class);
        $boms = app(BomService::class);
        $piece = $this->units['Adet']->getKey();

        // çerçeve => [model kodu, ad, kW, d/dk, kutup, rulman, ölçek, verim sınıfı, GTİP, net ağırlık]
        // GTİP değerleri demo içindir; gerçek kodlar dış ticaret sorumlusuyla teyit edilmelidir.
        $frames = [
            '71' => ['M71-037-4', '71 Tip 0,37 kW 4 Kutup Trifaze', '0,37', 1400, 4, 'KOM-RUL-6202', '1', 'IE2', '850151000000', '5,8'],
            '80' => ['M80-075-4', '80 Tip 0,75 kW 4 Kutup Trifaze', '0,75', 1420, 4, 'KOM-RUL-6204', '1,6', 'IE3', '850151000000', '9,6'],
            '90S' => ['M90S-110-4', '90S Tip 1,1 kW 4 Kutup Trifaze', '1,1', 1430, 4, 'KOM-RUL-6205', '2,2', 'IE3', '850152200000', '14,2'],
        ];

        foreach ($frames as $frame => [$code, $name, $kw, $rpm, $poles, $bearing, $scale, $efficiency, $gtip, $weight]) {
            $model = $models->create([
                'code' => $code, 'name' => $name, 'power_kw' => $kw, 'speed_rpm' => $rpm, 'voltage' => '230/400 V',
                'frequency_hz' => 50, 'pole_count' => $poles, 'frame_size' => $frame, 'phase' => MotorPhase::ThreePhase,
                'mounting_type' => 'B3', 'protection_class' => 'IP55', 'efficiency_class' => $efficiency,
                'description' => 'Alüminyum gövdeli asenkron motor', 'gtip_code' => $gtip, 'net_weight_kg' => $weight,
                'subtype_id' => $this->subtypeFor('MAMUL', PartType::FinishedGood),
            ]);
            $this->models[$frame] = $model;

            $semi = fn (string $prefix, string $label) => $this->parts["{$prefix}-{$frame}"] = $parts->create([
                'code' => "{$prefix}-{$frame}", 'name' => "{$label} {$frame} tip ({$kw} kW)", 'type' => PartType::SemiFinished,
                'unit_id' => $piece, 'subtype_id' => $this->subtypeFor($prefix, PartType::SemiFinished),
            ]);

            $shaft = $semi('YM-MIL', 'İşlenmiş mil');
            // Pres: stator ve rotor paketi aynı kalıpta birlikte basılır (rotor paketi eş ürün).
            $statorPack = $semi('YM-STP', 'Stator paketi');
            $rotorPack = $semi('YM-RTP', 'Rotor paketi');
            $stator = $semi('YM-STATOR', 'Bobinajlı stator');
            $rotor = $semi('YM-ROTOR', 'Enjekte rotor');
            $shaftRotor = $semi('YM-MILROTOR', 'Mil+rotor');
            $body = $semi('YM-GOVDE', 'Gövde');
            $cover = $semi('YM-KAPAK', 'Kapak/flanş');

            $q = fn (string $base) => Quantity::mul(Quantity::parse($base), Quantity::parse($scale));
            $fan = $frame === '90S' ? '90' : $frame;

            $diameter = ['71' => '20', '80' => '25', '90S' => '30'][$frame];
            // Mil: brüt 2,2 kg, talaş 0,5 kg (71 tip; diğerleri ölçekli).
            $boms->add($shaft, $this->parts["HM-MIL-C45-{$diameter}"], Quantity::sub($q('2,2'), $q('0,5')), 0, $q('0,5'));
            // Stator + rotor paketi: sac brüt 6,61 kg, fire 2,88 kg (71 tip 70 mm); fire hurda kartına girer.
            $boms->add($statorPack, $this->parts['HM-SAC-050'], Quantity::sub($q('6,61'), $q('2,88')), 0, $q('2,88'), $this->parts['HM-HURDA-SAC']);
            $boms->addCoProduct($statorPack, $rotorPack, 1);
            $boms->add($stator, $statorPack, 1);
            $boms->add($stator, $this->parts['HM-BAKIR-063'], $q('0,45'), 2);
            $boms->add($stator, $this->parts['DS-IZOLASYON'], $q('0,8'), 5);
            $boms->add($stator, $this->parts['DS-VERNIK'], $q('0,15'), 5);
            $boms->add($rotor, $rotorPack, 1);
            $boms->add($rotor, $this->parts['HM-ALU-ETIAL'], $q('0,35'), 3);
            $boms->add($shaftRotor, $rotor, 1);
            $boms->add($shaftRotor, $shaft, 1);
            $boms->add($body, $this->parts['HM-ALU-ETIAL'], $q('1,1'), 3);
            $boms->add($cover, $this->parts['HM-ALU-ETIAL'], $q('0,4'), 3);

            $finished = $model->finishedPart;
            $boms->add($finished, $stator, 1);
            $boms->add($finished, $shaftRotor, 1);
            $boms->add($finished, $body, 1);
            $boms->add($finished, $cover, 2);
            $boms->add($finished, $this->parts[$bearing], 2);
            $boms->add($finished, $this->parts['KOM-KLEMENS'], 1);
            $boms->add($finished, $this->parts["KOM-FAN-{$fan}"], 1);
            $boms->add($finished, $this->parts["KOM-TAS-{$fan}"], 1);
            $boms->add($finished, $this->parts['DS-CIVATA-M5'], 8, 2);
            $boms->add($finished, $this->parts['DS-ETIKET'], 1);
        }

        // Üretimden kalkmış model: pasif örneği (reçetesiz).
        $old = $models->create([
            'code' => 'M71-025-M', 'name' => '71 Tip 0,25 kW Monofaze (üretimden kalktı)', 'power_kw' => '0,25',
            'speed_rpm' => 1380, 'voltage' => '230 V', 'frequency_hz' => 50, 'pole_count' => 4, 'frame_size' => '71',
            'phase' => MotorPhase::SinglePhase, 'mounting_type' => 'B14', 'protection_class' => 'IP54',
        ]);
        $models->deactivate($old);
    }

    /** Markalar: kendi ürettiklerimiz "Tem Motor", demo tedarikçinin rulmanları kendi markası. */
    private function assignBrands(): void
    {
        $brands = app(BrandService::class);
        $own = $brands->create(['name' => 'Tem Motor']);
        $bearing = $brands->create(['name' => 'Rulman Market']);
        $parts = app(PartService::class);

        foreach (Part::query()->whereIn('type', [PartType::SemiFinished->value, PartType::FinishedGood->value])->orWhere('code', 'like', 'KOM-RUL-%')->get() as $part) {
            $brand = str_starts_with($part->code, 'KOM-RUL-') ? $bearing : $own;
            $parts->update($part, ['brand_id' => $brand->getKey()], $part->lock_version);
        }

        $sac = Part::query()->where('code', 'HM-SAC-050')->firstOrFail();
        $parts->update($sac, ['description' => 'M470 kalite, 0,50 mm; preste paket boyuna göre basılır.'], $sac->lock_version);
    }

    /** Serbest lisanslı örnek görseller (database/seeders/demo-images, kaynaklar KAYNAKLAR.md'de). */
    private function attachImages(): void
    {
        $images = [
            'HM-BAKIR-063' => 'bakir-tel', 'HM-ALU-ETIAL' => 'aluminyum', 'HM-MIL-C45-20' => 'mil-celigi', 'HM-MIL-C45-25' => 'mil-celigi', 'HM-MIL-C45-30' => 'mil-celigi',
            'KOM-RUL-6202' => 'rulman', 'KOM-RUL-6204' => 'rulman', 'KOM-RUL-6205' => 'rulman',
        ];

        foreach ($this->models as $frame => $model) {
            $images["YM-STATOR-{$frame}"] = 'bobinajli-stator';
            $images["YM-ROTOR-{$frame}"] = 'rotor';
            $images[$model->finishedPart->code] = 'motor';
        }

        $disk = Storage::disk(PartService::IMAGE_DISK);
        $service = app(PartService::class);

        foreach ($images as $code => $file) {
            $part = Part::query()->where('code', $code)->firstOrFail();
            $path = PartService::IMAGE_DIRECTORY."/demo-{$file}.jpg";

            if (! $disk->exists($path)) {
                $disk->put($path, file_get_contents(database_path("seeders/demo-images/{$file}.jpg")));
            }

            $service->update($part, ['image_path' => $path], $part->lock_version);
        }
    }

    private function createCustomers(): void
    {
        $service = app(CustomerService::class);

        // anahtar => [ad, müşteri, tedarikçi, vade, para birimi, telefon, vergi dairesi, adres, VKN ilk 9 hane]
        $customers = [
            'akdeniz' => ['Akdeniz Pompa Sanayi A.Ş.', true, false, 30, Currency::TRY, '0232 328 41 10', 'Kemalpaşa', 'Ankara Asfaltı No: 112, Kemalpaşa / İzmir', '012345678'],
            'bursafan' => ['Bursa Fan ve Havalandırma Ltd. Şti.', true, false, 45, Currency::TRY, '0224 243 55 70', 'Nilüfer', 'Nilüfer OSB 4. Cadde No: 9, Bursa', '123456789'],
            'anadolu' => ['Anadolu Konveyör Makine San. Tic. Ltd. Şti.', true, false, 15, Currency::TRY, '0312 815 20 30', 'Sincan', 'Ankara Sanayi Sitesi 1. Blok No: 22, Sincan / Ankara', '234567891'],
            'ege' => ['Ege Tarım Makinaları A.Ş.', true, false, 60, Currency::TRY, '0256 231 77 44', 'Efeler', 'İzmir Yolu 8. km, Efeler / Aydın', '345678912'],
            'marmara' => ['Marmara Su Teknolojileri A.Ş.', true, false, 30, Currency::EUR, '0216 593 18 00', 'Tuzla', 'Tuzla Kimya OSB, Kristal Cad. No: 4, Tuzla / İstanbul', '456789123'],
            // Doğrudan ihracat (KDV 11/1-a) ve ihraç kayıtlı satış (KDV 11/1-c) yapılan cariler.
            'hellas' => ['Hellas Pumps S.A.', true, false, 45, Currency::EUR, '+30 2310 795 400', null, 'Sindos Industrial Area, Block 31, 57022 Thessaloniki', null],
            'ihracatci' => ['Anadolu Dış Ticaret A.Ş.', true, false, 30, Currency::TRY, '0212 444 32 10', 'Bakırköy', 'Ataköy 7-8-9-10. Kısım, Bakırköy / İstanbul', '135792468'],
            'ozturk' => ['Öztürk Elektrik Motor Servis Ltd. Şti.', true, true, 15, Currency::TRY, '0262 641 09 09', 'Gebze', 'Gebze Plastikçiler OSB, Gebze / Kocaeli', '567891234'],
            'demirsan' => ['Demirsan Silisli Sac San. Ltd. Şti.', false, true, 30, Currency::TRY, '0262 751 33 00', 'Dilovası', 'Dilovası OSB 2. Kısım, Kocaeli', '678912345'],
            'kablotel' => ['Kablo-Tel Bakır Sanayi A.Ş.', false, true, 45, Currency::TRY, '0216 394 66 20', 'Kartal', 'Yakacık Sanayi, Kartal / İstanbul', '789123456'],
            'kuzeyalu' => ['Kuzey Alüminyum Döküm Ltd. Şti.', false, true, 30, Currency::TRY, '0224 411 82 82', 'Osmangazi', 'Demirtaş OSB, Osmangazi / Bursa', '891234567'],
            'rulman' => ['Rulman Market Endüstriyel Ltd. Şti.', false, true, 30, Currency::TRY, '0212 671 45 45', 'İkitelli', 'İkitelli OSB, Başakşehir / İstanbul', '912345678'],
        ];

        foreach ($customers as $key => [$name, $isCustomer, $isSupplier, $term, $currency, $phone, $office, $address, $firstNine]) {
            $foreign = $key === 'hellas';
            $this->customers[$key] = $service->create([
                'name' => $name, 'is_customer' => $isCustomer, 'is_supplier' => $isSupplier, 'payment_term_days' => $term,
                'default_currency' => $currency, 'phone' => $phone, 'tax_office' => $office, 'address' => $address,
                'tax_number' => $firstNine ? $firstNine.TaxNumber::vknCheckDigit($firstNine) : null,
                'country_code' => $foreign ? 'GR' : 'TR',
                'foreign_tax_id' => $foreign ? 'EL094019245' : null,
                'email' => 'muhasebe@'.$key.'.example',
            ]);
        }
    }

    private function createRatesAndPrices(CarbonImmutable $start): void
    {
        $rates = app(ExchangeRateService::class);

        // TCMB'ye erişilmez; demo kurlar elle girilmiş gibi kaydedilir (iş günleri).
        for ($day = $start->subDays(10)->startOfDay(), $i = 0; $day->lte($this->realNow); $day = $day->addDay(), $i++) {
            if ($day->isWeekend()) {
                continue;
            }

            foreach ([Currency::EUR->value => ['47.20', '0.0120'], Currency::USD->value => ['40.90', '0.0080']] as $currency => [$base, $slope]) {
                $selling = bcadd(bcadd($base, bcmul($slope, (string) $i, 6), 6), number_format(sin($i / 6) * 0.15, 6, '.', ''), 4);
                $buying = bcmul($selling, '0.9965', 4);
                $rates->saveManual($currency, $day, str_replace('.', ',', $buying), str_replace('.', ',', $selling), $this->users['muhasebe']);
            }
        }

        $prices = app(PriceService::class);
        $list = ['71' => '4250', '80' => '6900', '90S' => '9400'];

        foreach ($list as $frame => $price) {
            $prices->create(['motor_model_id' => $this->models[$frame]->getKey(), 'currency' => Currency::TRY, 'unit_price' => $price, 'valid_from' => $start]);
        }

        $prices->create(['motor_model_id' => $this->models['71']->getKey(), 'customer_id' => $this->customers['bursafan']->getKey(), 'currency' => Currency::TRY, 'unit_price' => '3950', 'valid_from' => $start]);

        foreach (['71' => '88', '80' => '142', '90S' => '195'] as $frame => $price) {
            $prices->create(['motor_model_id' => $this->models[$frame]->getKey(), 'customer_id' => $this->customers['marmara']->getKey(), 'currency' => Currency::EUR, 'unit_price' => $price, 'valid_from' => $start]);
            $prices->create(['motor_model_id' => $this->models[$frame]->getKey(), 'customer_id' => $this->customers['hellas']->getKey(), 'currency' => Currency::EUR, 'unit_price' => (string) round((int) $price * 0.95), 'valid_from' => $start]);
        }

        // İleri tarihli zam: liste fiyatları gelecek ayın başında %8 artar.
        foreach ($list as $frame => $price) {
            $prices->create([
                'motor_model_id' => $this->models[$frame]->getKey(), 'currency' => Currency::TRY,
                'unit_price' => (string) round((int) $price * 1.08, -1), 'valid_from' => $this->realNow->addMonthNoOverflow()->startOfMonth(),
            ]);
        }
    }

    private function createAccountsAndOpenings(CarbonImmutable $start): void
    {
        $service = app(CashAccountService::class);

        $this->accounts['kasa'] = $service->create(['kind' => CashAccountKind::Cash, 'currency' => Currency::TRY, 'name' => 'Kasa']);
        $this->accounts['kasa_eur'] = $service->create(['kind' => CashAccountKind::Cash, 'currency' => Currency::EUR, 'name' => 'Döviz Kasası (EUR)']);
        $this->accounts['ziraat'] = $service->create(['kind' => CashAccountKind::Bank, 'currency' => Currency::TRY, 'name' => 'Ziraat Bankası TL', 'bank_name' => 'T.C. Ziraat Bankası', 'branch' => 'Kemalpaşa OSB Şubesi', 'iban' => $this->iban('00010', '0001234567')]);
        $this->accounts['is_eur'] = $service->create(['kind' => CashAccountKind::Bank, 'currency' => Currency::EUR, 'name' => 'İş Bankası EUR', 'bank_name' => 'Türkiye İş Bankası', 'branch' => 'Kemalpaşa Şubesi', 'iban' => $this->iban('00064', '0009876543')]);

        $this->as('muhasebe', function () use ($start): void {
            $ledger = app(LedgerService::class);
            $user = $this->users['muhasebe'];

            $ledger->openCashBalance($this->accounts['kasa'], '25000', $start, $user);
            $ledger->openCashBalance($this->accounts['ziraat'], '850000', $start, $user);
            $ledger->openCashBalance($this->accounts['is_eur'], '12000', $start, $user);

            // Mikro'dan devralınan cari bakiyeler.
            $ledger->openCustomerBalance($this->customers['akdeniz'], Currency::TRY, '180000', $start, $user);
            $ledger->openCustomerBalance($this->customers['ege'], Currency::TRY, '64500', $start, $user);
            $ledger->openCustomerBalance($this->customers['rulman'], Currency::TRY, '-45000', $start, $user);
        });
    }

    // --- Haftalık üretim temposu ----------------------------------------------------------------------------

    private function scheduleWeeks(): void
    {
        $thisMonday = $this->realNow->startOfWeek();
        $start = $this->realNow->subDays(120)->setTime(8, 0);

        // Devir bakiyelerinin tahsili/ödenmesi.
        $firstMonday = $thisMonday->subWeeks($this->weeks - 1);
        $this->schedule($firstMonday->setTime(11, 0), fn () => $this->receive('akdeniz', '180000', PaymentMethod::Bank));
        $this->schedule($firstMonday->addDays(2)->setTime(11, 0), fn () => $this->pay('rulman', '45000', PaymentMethod::Cheque, $firstMonday->addDays(32)));
        $this->schedule($start->addDays(20)->setTime(10, 0), fn () => $this->as('depo', fn () => app(StockService::class)->stockOut(
            $this->parts['ES-KESMEYAGI'], '10', $this->users['depo'], 'Atölye kullanımı (torna)'
        )));

        $customerCycle = ['akdeniz', 'hellas', 'anadolu', 'ege', 'ihracatci', 'marmara', 'ozturk', 'hellas', 'bursafan', 'ihracatci'];

        for ($w = $this->weeks - 1; $w >= 0; $w--) {
            $monday = $thisMonday->subWeeks($w);
            $index = $this->weeks - 1 - $w;
            $isLast = $w === 0;

            $orderCustomers = [$customerCycle[$index % count($customerCycle)], $customerCycle[($index + 3) % count($customerCycle)]];

            $this->schedule($monday->setTime(8, 30), fn () => $this->planWeek($monday, $orderCustomers, $isLast, $index));

            // Düzenli haftalık teslimatlar (hafta sonunda alış faturasına bağlanır).
            $this->schedule($monday->setTime(8, 0), fn () => $this->receiveDelivery(['HM-SAC-050' => '300', 'HM-BAKIR-063' => '60']));

            // Atölye sarfı (endirekt): her hafta.
            $this->schedule($monday->addDays(4)->setTime(16, 30), fn () => $this->as('depo', function (): void {
                app(StockService::class)->stockOut($this->parts['ES-ELDIVEN'], '12', $this->users['depo'], 'Atölye kullanımı');
            }));

            // Ara olaylar: sayım, ters kayıt, iade, tedarikçiye iade.
            if ($index === 2) {
                $this->schedule($monday->addDays(4)->setTime(17, 0), fn () => $this->as('depo', function (): void {
                    $part = $this->parts['DS-VERNIK']->fresh('stockBalances');
                    $counted = Quantity::sub($part->defaultQuantity(), '0.4');
                    app(StockService::class)->adjustCount($part, $counted, $this->users['depo'], 'Haftalık sayım: kapakta kuruma kaybı');
                }));
            }

            if ($index === 3) {
                $this->schedule($monday->setTime(10, 15), fn () => $this->as('depo', function (): void {
                    $stock = app(StockService::class);
                    $wrong = $stock->stockIn($this->parts['DS-CIVATA-M5'], '5000', $this->users['depo'], 'İrsaliye 4471');
                    $stock->correct($wrong, $this->users['depo'], 'Miktar yanlış girildi (500 olacaktı)');
                    $stock->stockIn($this->parts['DS-CIVATA-M5'], '500', $this->users['depo'], 'İrsaliye 4471');
                }));
                $this->schedule($monday->addDays(1)->setTime(15, 0), fn () => $this->as('depo', function (): void {
                    $stock = app(StockService::class);
                    $stock->returnToStock($this->parts['KOM-RUL-6202'], '4', $this->users['depo'], 'Üretimden artan rulman iadesi');
                    $stock->stockOut($this->parts['KOM-KLEMENS'], '20', $this->users['depo'], 'Tedarikçiye iade: kapak kilitleri kırık parti');
                }));
            }
        }
    }

    /**
     * Haftanın planı: siparişler, yarı mamul ve motor iş emirleri; sevk, faturalar ve ödemeler sonraki olaylarla.
     *
     * @param  list<string>  $orderCustomers
     */
    private function planWeek(CarbonImmutable $monday, array $orderCustomers, bool $isLast, int $index): void
    {
        $orders = [];
        $demand = [];

        foreach ($orderCustomers as $customer) {
            $frames = $this->pick(['71', '80', '90S'], $this->rand(1, 2));
            $items = [];

            foreach ($frames as $frame) {
                $quantity = [10, 15, 20, 25][$this->rand(0, 3)];
                $items[] = ['motor_model_id' => $this->models[$frame]->getKey(), 'quantity' => $quantity];
                $demand[$frame] = ($demand[$frame] ?? 0) + $quantity;
            }

            $orders[] = $this->as('yonetici', fn () => app(OrderService::class)->create([
                'customer_id' => $this->customers[$customer]->getKey(), 'order_date' => $monday, 'due_date' => $monday->addDays(14),
                'customer_reference' => 'PO-'.$monday->format('ymd').'-'.$this->rand(100, 999), 'items' => $items,
            ]));
        }

        // İptal edilen sipariş örneği (iş emri açılmadan).
        if ($index === 1) {
            $cancelled = $this->as('yonetici', fn () => app(OrderService::class)->create([
                'customer_id' => $this->customers['anadolu']->getKey(), 'order_date' => $monday, 'due_date' => $monday->addDays(21),
                'items' => [['motor_model_id' => $this->models['90S']->getKey(), 'quantity' => 30]],
            ]));
            $this->schedule($monday->addDays(1)->setTime(9, 0), fn () => $this->as('yonetici', fn () => app(OrderService::class)->cancel($cancelled, 'Müşteri projeyi erteledi')));
        }

        // Stok için üretim (siparişsiz), bir hafta gecikmiş iş emri ve geri alma örneği.
        $stockRun = $index % 3 === 0 ? ['71', 10] : null;
        if ($stockRun !== null) {
            $demand[$stockRun[0]] = ($demand[$stockRun[0]] ?? 0) + $stockRun[1];
        }

        $overdue = ! $isLast && $index === $this->weeks - 2;
        if ($overdue) {
            $demand['90S'] = ($demand['90S'] ?? 0) + 5;
        }

        // Yarı mamul iş emirleri üretim sırasıyla: mil, pres (stator + rotor paketi), bobinaj, döküm, mil+rotor, gövde, kapak.
        $semiOrder = ['YM-MIL', 'YM-STP', 'YM-STATOR', 'YM-ROTOR', 'YM-MILROTOR', 'YM-GOVDE', 'YM-KAPAK'];
        $hour = 8;

        foreach ($demand as $frame => $quantity) {
            foreach ($semiOrder as $step => $prefix) {
                $part = $this->parts["{$prefix}-{$frame}"];
                $workOrder = $this->createWorkOrder($part, $prefix === 'YM-KAPAK' ? $quantity * 2 : $quantity, 'operator2', $monday->addDay(), $monday->addDays(2));
                $startAt = $monday->addDay()->setTime($hour, 0)->addMinutes($step * 20);
                $doneAt = $monday->addDay()->setTime(12 + $step, 0);
                $this->schedule($startAt, fn () => $this->start($workOrder, 'operator2'));
                $this->schedule($doneAt, fn () => $this->complete($workOrder, 'operator2'));
            }
        }

        // Motor iş emirleri: sipariş kalemine bağlı.
        foreach ($orders as $order) {
            $workOrders = [];

            foreach ($order->items as $item) {
                $frame = array_search($item->motor_model_id, array_map(fn ($m) => $m->getKey(), $this->models), true);
                $late = $index % 4 === 1;
                $plannedEnd = $isLast ? $monday->addDays(8) : $monday->addDays($late ? 2 : 3);
                $workOrder = $this->createWorkOrder($this->models[$frame]->finishedPart, $item->quantity, 'operator1', $monday->addDays(2), $plannedEnd, $item->getKey());
                $workOrders[] = $workOrder;

                $this->schedule($monday->addDays(2)->setTime(8, 0), fn () => $this->start($workOrder, 'operator1'));
                $this->schedule($isLast ? $monday->addDays(7)->setTime(16, 0) : $monday->addDays(3)->setTime(16, 0), fn () => $this->complete($workOrder, 'operator1'));
            }

            $this->schedule($monday->addDays(4)->setTime(10, 0), fn () => $this->ship($order, $workOrders));
            $this->schedule($monday->addDays(4)->setTime(14, 0), fn () => $this->invoiceOrder($order));
        }

        if ($stockRun !== null) {
            [$frame, $quantity] = $stockRun;
            $workOrder = $this->createWorkOrder($this->models[$frame]->finishedPart, $quantity, 'operator1', $monday->addDays(2), $monday->addDays(4));
            $this->schedule($monday->addDays(2)->setTime(9, 0), fn () => $this->start($workOrder, 'operator1'));
            $this->schedule($monday->addDays(3)->setTime(11, 0), fn () => $this->complete($workOrder, 'operator1'));

            // Geri alma: testte sargı hatası bulunur, motorlar iptal edilir ve iş emri yeniden tamamlanır.
            if ($index === 3) {
                $this->schedule($monday->addDays(3)->setTime(15, 0), fn () => $this->as('yonetici', fn () => app(ProductionService::class)->reverse(
                    $workOrder->fresh(), $this->users['yonetici'], 'Son testte sargı izolasyon hatası; motorlar yeniden sarılacak'
                )));
                $this->schedule($monday->addDays(4)->setTime(15, 0), fn () => $this->complete($workOrder, 'operator1'));
            }
        }

        if ($overdue) {
            // Planlanan bitişi geçmiş, hâlâ üretimde: dashboard'da "gecikmiş".
            $workOrder = $this->createWorkOrder($this->models['90S']->finishedPart, 5, 'operator1', $monday->addDays(2), $monday->addDays(4), notes: 'Stok için; kapak dökümü bekleniyor');
            $this->schedule($monday->addDays(3)->setTime(8, 0), fn () => $this->start($workOrder, 'operator1'));
        }

        if ($index === 2) {
            // İptal edilen iş emri örneği.
            $workOrder = $this->createWorkOrder($this->models['80']->finishedPart, 8, 'operator1', $monday->addDays(2), $monday->addDays(4));
            $this->schedule($monday->setTime(15, 0), fn () => $this->as('yonetici', fn () => app(WorkOrderService::class)->cancel($workOrder->fresh(), $this->users['yonetici'], 'Stok üretimi ertelendi')));
        }

        if ($isLast) {
            // Gelecek haftanın planı: atanmamış ve atanmış planlı iş emirleri.
            $this->createWorkOrder($this->models['71']->finishedPart, 20, 'operator1', $monday->addWeek(), $monday->addWeek()->addDays(3));
            $this->createWorkOrder($this->models['80']->finishedPart, 10, null, $monday->addWeek()->addDays(2), $monday->addWeek()->addDays(4));
        }

        // Haftalık alış faturaları (depo girişlerine bağlanır).
        $this->schedule($monday->addDays(4)->setTime(15, 0), fn () => $this->invoicePurchases($monday));
    }

    private function createWorkOrder(Part $product, int $quantity, ?string $operator, CarbonImmutable $plannedStart, CarbonImmutable $plannedEnd, ?int $orderItemId = null, ?string $notes = null): WorkOrder
    {
        return $this->as('yonetici', fn () => app(WorkOrderService::class)->create([
            'product_part_id' => $product->getKey(), 'quantity' => $quantity, 'order_item_id' => $orderItemId,
            'assigned_user_id' => $operator ? $this->users[$operator]->getKey() : null,
            'planned_start' => $plannedStart, 'planned_end' => $plannedEnd, 'notes' => $notes,
        ], $this->users['yonetici']));
    }

    private function start(WorkOrder $workOrder, string $operator): void
    {
        $workOrder = $workOrder->fresh();

        if ($workOrder->status === WorkOrderStatus::Planned) {
            $this->as($operator, fn () => app(WorkOrderService::class)->start($workOrder, $this->users[$operator]));
        }
    }

    private function complete(WorkOrder $workOrder, string $operator): void
    {
        $workOrder = $workOrder->fresh(['materials.part.unit', 'materials.part.stockBalances', 'materials.warehouse']);

        if ($workOrder->status !== WorkOrderStatus::InProgress) {
            return;
        }

        // Ara sıra fireli sac: standart firenin bir buçuk katı.
        $scraps = [];
        foreach ($workOrder->materials as $material) {
            if ($material->part->code === 'HM-SAC-050' && $this->rand(0, 4) === 0) {
                $scraps[$material->part_id] = Quantity::mul((string) $material->planned_scrap, '1.5');
            }
        }

        $this->ensureMaterials($workOrder, $scraps);
        $this->as($operator, fn () => app(ProductionService::class)->complete($workOrder, $this->users[$operator], $scraps));
    }

    /**
     * Eksik malzemeyi tamamlar: ana depoda yoksa depo tedarikçi lotu kadar girer (girişler hafta sonunda alış
     * faturasına bağlanır), sonra iş emrinin malzeme deposuna (üretim deposu) eksik kadar besleme transferi yapar.
     */
    private function ensureMaterials(WorkOrder $workOrder, array $scraps): void
    {
        foreach ($workOrder->materials as $material) {
            $part = $material->part;
            $need = Quantity::add((string) $material->required_quantity, $scraps[$part->getKey()] ?? (string) $material->planned_scrap);
            $balance = $material->available();

            if (Quantity::compare($balance, $need) >= 0) {
                continue;
            }

            if ($part->type === PartType::SemiFinished) {
                throw new RuntimeException("Demo kurgu hatası: {$part->code} yarı mamulü yetersiz.");
            }

            $shortage = Quantity::sub($need, $balance);
            $home = app(WarehouseService::class)->defaultFor($part);
            $homeBalance = $home->is($material->warehouse) ? $balance : $part->quantityIn($home->getKey());

            if (Quantity::compare($homeBalance, $home->is($material->warehouse) ? $need : $shortage) < 0) {
                $lot = match ($part->unit->name) {
                    'Kg' => '50', 'Litre' => '10', default => '100',
                };
                $missing = Quantity::sub($home->is($material->warehouse) ? $need : $shortage, $homeBalance);
                $quantity = Quantity::add(Quantity::mul(Quantity::ceil(bcdiv($missing, $lot, 3), 0), $lot), $lot);

                $movement = $this->as('depo', fn () => app(StockService::class)->stockIn($part, $quantity, $this->users['depo'], 'Tedarikçi teslimatı', warehouse: $home));
                $this->pendingReceipts[$this->partSupplier[$part->code]][$part->code][] = $movement->getKey();
            }

            if (! $home->is($material->warehouse)) {
                $this->as('depo', fn () => app(StockService::class)->transfer($part, $home, $material->warehouse, $shortage, $this->users['depo'], "Hat besleme: {$workOrder->work_order_number}"));
            }

            $part->load('stockBalances');
        }
    }

    /** @param  array<string, string>  $quantities  parça kodu => miktar */
    private function receiveDelivery(array $quantities): void
    {
        foreach ($quantities as $code => $quantity) {
            $movement = $this->as('depo', fn () => app(StockService::class)->stockIn($this->parts[$code], $quantity, $this->users['depo'], 'Haftalık teslimat'));
            $this->pendingReceipts[$this->partSupplier[$code]][$code][] = $movement->getKey();
        }
    }

    /** @param  list<WorkOrder>  $workOrders */
    private function ship(Order $order, array $workOrders): void
    {
        $units = MotorUnit::query()
            ->whereIn('work_order_id', array_map(fn (WorkOrder $w) => $w->getKey(), $workOrders))
            ->where('status', MotorUnitStatus::InStock->value)
            ->get();

        if ($units->isNotEmpty()) {
            $this->as('depo', fn () => app(ProductionService::class)->ship($units, $this->users['depo'], 'İrsaliye '.$order->order_number));
        }
    }

    private function invoiceOrder(Order $order): void
    {
        $customer = $order->customer;
        $discount = ['akdeniz' => '15', 'bursafan' => '20', 'ege' => '30', 'ozturk' => '15'][$this->customerKey($customer)] ?? '0';

        $this->as('muhasebe', function () use ($order, $customer, $discount): void {
            $invoices = app(InvoiceService::class);
            $key = $this->customerKey($customer);
            $invoice = $invoices->createDraft([
                'type' => InvoiceType::SalesInvoice, 'customer_id' => $customer->getKey(), 'notes' => "Sipariş {$order->order_number}",
                'export_type' => match ($key) {
                    'hellas' => ExportType::Export,
                    'ihracatci' => ExportType::RegisteredExport,
                    default => null,
                },
            ]);
            $hasLine = false;

            foreach ($order->items as $item) {
                $units = MotorUnit::query()->where('order_id', $order->getKey())->where('motor_model_id', $item->motor_model_id)
                    ->where('status', MotorUnitStatus::Shipped->value)
                    ->whereDoesntHave('invoiceLines')
                    ->get();

                if ($units->isNotEmpty()) {
                    $invoices->addMotorLine($invoice, $units, null, $discount);
                    $hasLine = true;
                }
            }

            if (! $hasLine) {
                $invoices->deleteDraft($invoice);

                return;
            }

            if ($this->rand(0, 2) === 0) {
                $invoices->addLine($invoice, ['description' => 'Nakliye', 'quantity' => 1, 'unit' => 'Adet', 'unit_price' => $customer->default_currency === Currency::EUR ? '45' : '1750']);
            }

            if ($key === 'hellas') {
                // Kap ve ağırlık: 8 motorluk paletler, ambalajla brüt ağırlık %15 fazla (net ağırlık kesimde hesaplanır).
                $motors = MotorUnit::query()->whereHas('invoiceLines', fn ($q) => $q->where('invoice_id', $invoice->getKey()))->with('motorModel')->get();
                $net = $motors->reduce(fn (string $sum, MotorUnit $m) => Quantity::add($sum, (string) $m->motorModel->net_weight_kg), '0');
                $invoice = $invoices->updateDraft($invoice->fresh(), [
                    'incoterm' => Incoterm::FCA, 'transport_mode' => TransportMode::Road, 'package_type' => PackageType::Pallet,
                    'package_count' => (int) ceil($motors->count() / 8), 'gross_weight_kg' => Quantity::add(Quantity::mul($net, '1.15'), '25'),
                ], $invoice->fresh()->lock_version);
            }

            $invoice = $invoices->issue($invoice->fresh(), $this->users['muhasebe']);
            $this->scheduleCollection($invoice);
            $this->scheduleCustomsDeclaration($invoice);
        });
    }

    /** Gümrük beyannamesi: ihracatta birkaç gün içinde, ihraç kayıtlı satışta ihracatçı malı çıkarınca (yaklaşık 3 hafta). */
    private function scheduleCustomsDeclaration(Invoice $invoice): void
    {
        if ($invoice->export_type === null) {
            return;
        }

        $date = CarbonImmutable::parse($invoice->invoice_date)->addDays($invoice->export_type === ExportType::Export ? 2 : 20)->setTime(11, 30);

        $this->schedule($date, fn () => $this->as('muhasebe', fn () => app(InvoiceService::class)->recordCustomsDeclaration(
            $invoice, $date->format('y').'341300EX'.str_pad((string) $this->rand(1, 999999), 6, '0', STR_PAD_LEFT), $date, $this->users['muhasebe']
        )));
    }

    /** Müşterinin ödeme alışkanlığına göre tahsilat. */
    private function scheduleCollection(Invoice $invoice): void
    {
        $key = $this->customerKey($invoice->customer);
        $date = CarbonImmutable::parse($invoice->invoice_date)->setTime(11, 0);
        $total = (string) $invoice->grand_total;

        match ($key) {
            'akdeniz' => $this->schedule($date->addDays(28), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'bursafan' => $this->schedule($date->addDays(40), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'ozturk' => $this->schedule($date->addDays(12), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'marmara' => $this->schedule($date->addDays(25), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'hellas' => $this->schedule($date->addDays(35), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'ihracatci' => $this->schedule($date->addDays(30), fn () => $this->receive($key, $total, PaymentMethod::Bank)),
            'ege' => $this->schedule($date->addDays(5), fn () => $this->receive($key, $total, PaymentMethod::Cheque, $date->addDays(65))),
            // Anadolu yalnızca ilk faturasının bir kısmını öder: vadesi geçmiş alacak örneği.
            'anadolu' => Invoice::query()->where('customer_id', $invoice->customer_id)->count() === 1
                ? $this->schedule($date->addDays(20), fn () => $this->receive($key, '20000', PaymentMethod::Cash))
                : null,
            default => null,
        };
    }

    private function invoicePurchases(CarbonImmutable $monday): void
    {
        foreach ($this->pendingReceipts as $supplier => $byPart) {
            $this->as('muhasebe', function () use ($supplier, $byPart, $monday): void {
                $invoices = app(InvoiceService::class);
                $invoice = $invoices->createDraft([
                    'type' => InvoiceType::PurchaseInvoice, 'customer_id' => $this->customers[$supplier]->getKey(),
                    'counterparty_number' => strtoupper(substr($supplier, 0, 3)).$monday->format('Y').str_pad((string) $this->rand(1, 99999), 6, '0', STR_PAD_LEFT),
                ]);

                foreach ($byPart as $code => $movementIds) {
                    $quantity = (string) StockMovement::query()->whereIn('id', $movementIds)->sum('quantity');
                    $invoices->addLine($invoice, [
                        'part_id' => $this->parts[$code]->getKey(), 'quantity' => $quantity, 'unit_price' => $this->purchasePrice[$code],
                        'stock_movement_ids' => $movementIds,
                    ]);
                }

                $invoice = $invoices->issue($invoice->fresh(), $this->users['muhasebe']);
                $total = (string) $invoice->grand_total;
                $date = CarbonImmutable::parse($invoice->invoice_date)->setTime(14, 0);

                // Kablo-Tel'e verilen çekle, diğerlerine havaleyle ödeme.
                if ($supplier === 'kablotel') {
                    $this->schedule($date->addDays(7), fn () => $this->pay($supplier, $total, PaymentMethod::Cheque, $date->addDays(37)));
                } else {
                    $this->schedule($date->addDays(30), fn () => $this->pay($supplier, $total, PaymentMethod::Bank));
                }
            });
        }

        $this->pendingReceipts = [];
    }

    private function receive(string $customer, string $amount, PaymentMethod $method, ?CarbonImmutable $chequeDue = null): void
    {
        $this->as('muhasebe', function () use ($customer, $amount, $method, $chequeDue): void {
            $currency = $this->customers[$customer]->default_currency;
            $account = match ($method) {
                PaymentMethod::Cash => $this->accounts[$currency === Currency::EUR ? 'kasa_eur' : 'kasa'],
                PaymentMethod::Bank => $this->accounts[$currency === Currency::EUR ? 'is_eur' : 'ziraat'],
                default => null,
            };

            $payment = app(PaymentService::class)->record([
                'direction' => PaymentDirection::Received, 'customer_id' => $this->customers[$customer]->getKey(),
                'method' => $method, 'amount' => str_replace('.', ',', $amount), 'currency' => $currency,
                'cash_account_id' => $account?->getKey(),
                'cheque_number' => $chequeDue ? (string) $this->rand(1000000, 9999999) : null,
                'cheque_bank' => $chequeDue ? 'Türkiye Halk Bankası' : null,
                'cheque_drawer' => $chequeDue ? $this->customers[$customer]->name : null,
                'cheque_due_date' => $chequeDue,
                'description' => $method === PaymentMethod::Bank ? 'Havale/EFT' : null,
            ], $this->users['muhasebe']);

            if ($payment->cheque_id !== null) {
                $this->scheduleChequeFate(Cheque::query()->findOrFail($payment->cheque_id));
            }
        });
    }

    /** Alınan çekler sırayla: tahsil edilir, tedarikçiye ciro edilir, portföyde kalır. */
    private function scheduleChequeFate(Cheque $cheque): void
    {
        $count = Cheque::query()->where('direction', 'received')->count();
        $due = CarbonImmutable::parse($cheque->due_date)->setTime(10, 0);

        match ($count % 3) {
            1 => $this->schedule($due, fn () => $this->as('muhasebe', fn () => app(ChequeService::class)->collect($cheque->fresh(), $this->accounts['ziraat'], $due, $this->users['muhasebe']))),
            2 => $this->schedule(CarbonImmutable::now()->addDays(3)->setTime(10, 0), fn () => $this->as('muhasebe', fn () => app(PaymentService::class)->record([
                'direction' => PaymentDirection::Made, 'customer_id' => $this->customers['demirsan']->getKey(),
                'method' => PaymentMethod::Endorsement, 'cheque_id' => $cheque->getKey(), 'description' => 'Müşteri çeki ciro',
            ], $this->users['muhasebe']))),
            default => null,
        };
    }

    private function pay(string $supplier, string $amount, PaymentMethod $method, ?CarbonImmutable $chequeDue = null): void
    {
        $this->as('muhasebe', function () use ($supplier, $amount, $method, $chequeDue): void {
            $payment = app(PaymentService::class)->record([
                'direction' => PaymentDirection::Made, 'customer_id' => $this->customers[$supplier]->getKey(),
                'method' => $method, 'amount' => str_replace('.', ',', $amount), 'currency' => Currency::TRY,
                'cash_account_id' => $method === PaymentMethod::Bank ? $this->accounts['ziraat']->getKey() : null,
                'cheque_number' => $chequeDue ? 'TM'.$this->rand(100000, 999999) : null,
                'cheque_bank' => $chequeDue ? 'T.C. Ziraat Bankası' : null,
                'cheque_due_date' => $chequeDue,
            ], $this->users['muhasebe']);

            if ($chequeDue !== null && $payment->cheque_id !== null) {
                $this->schedule($chequeDue->setTime(10, 0), fn () => $this->as('muhasebe', fn () => app(ChequeService::class)->pay(
                    Cheque::query()->findOrFail($payment->cheque_id), $this->accounts['ziraat'], $chequeDue, $this->users['muhasebe']
                )));
            }
        });
    }

    // --- Olay sırası ve yardımcılar -------------------------------------------------------------------------

    /** Olay, zamanı gelince (ve şu andan sonra değilse) çalışır. Olaylar yeni olay planlayabilir. */
    private function schedule(CarbonImmutable $at, Closure $run): void
    {
        $this->events[] = ['at' => $at, 'seq' => $this->sequence++, 'run' => $run];
    }

    private function runEvents(): void
    {
        while ($this->events !== []) {
            usort($this->events, fn ($a, $b) => [$a['at'], $a['seq']] <=> [$b['at'], $b['seq']]);
            $event = array_shift($this->events);

            if ($event['at']->gt($this->realNow)) {
                continue;
            }

            $this->at($event['at'], $event['run']);
        }
    }

    /** Uygulama saatini verilen ana ayarlayarak çalıştırır. */
    private function at(CarbonImmutable $at, Closure $run): mixed
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        return $run();
    }

    /** İşlemi kullanıcının oturumuyla yapar (denetim izinde işlemi yapan görünsün). */
    private function as(string $username, Closure $run): mixed
    {
        $previous = Auth::user();
        Auth::setUser($this->users[$username]);

        try {
            return $run();
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    private function customerKey(Customer $customer): string
    {
        return (string) array_search($customer->getKey(), array_map(fn (Customer $c) => $c->getKey(), $this->customers), true);
    }

    /** Tekrarlanabilir sözde rastgele sayı (her çalıştırmada aynı veri). */
    private function rand(int $min, int $max): int
    {
        $this->random = ($this->random * 1103515245 + 12345) % 2147483648;

        return $min + $this->random % ($max - $min + 1);
    }

    /** @param  list<string>  $values @return list<string> */
    private function pick(array $values, int $count): array
    {
        $picked = [];

        while (count($picked) < $count) {
            $value = $values[$this->rand(0, count($values) - 1)];
            $picked[$value] = $value;
        }

        return array_values($picked);
    }

    /** Geçerli kontrol haneli TR IBAN (banka kodu 5 hane, hesap 10 hane; rezerv 0). */
    private function iban(string $bank, string $account): string
    {
        $bban = $bank.'0'.str_pad($account, 16, '0', STR_PAD_LEFT);
        $numeric = $bban.'2927'.'00'; // T=29, R=27
        $check = 98 - (int) bcmod($numeric, '97');

        return 'TR'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$bban;
    }
}
