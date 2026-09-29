<?php

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerService;
use App\Support\TaxNumber;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('formdan müşteri oluşturulur, vergi no boşlukları atılır ve denetim izine yazılır', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'name' => ' Anadolu Pompa A.Ş. ', 'tax_number' => '123 456 7890', 'tax_office' => 'Kadıköy',
            'phone' => '0216 000 00 00', 'email' => 'Satis@Anadolu.test', 'address' => 'İstanbul',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::sole();

    expect($customer)
        ->name->toBe('Anadolu Pompa A.Ş.')
        ->tax_number->toBe('1234567890')
        ->tax_office->toBe('Kadıköy')
        ->email->toBe('satis@anadolu.test')
        ->is_active->toBeTrue()
        ->and(Activity::where('subject_type', 'customer')->where('subject_id', $customer->id)->exists())->toBeTrue();
});

it('vergi no, vergi dairesi, e-posta ve adres isteğe bağlıdır; boş değerler null olur', function () {
    $customer = app(CustomerService::class)->create(['name' => 'Şahıs', 'phone' => '0532 000 00 00', 'tax_number' => ' ', 'tax_office' => '', 'address' => ' ']);

    expect($customer->fresh())->tax_number->toBeNull()->tax_office->toBeNull()->address->toBeNull()->email->toBeNull();
});

it('vergi no 10 veya 11 rakam olmalıdır', function (string $value) {
    $this->actingAs($this->admin);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'X', 'tax_number' => $value])
        ->call('create')
        ->assertHasFormErrors(['tax_number' => 'regex']);

    expect(fn () => app(CustomerService::class)->create(['name' => 'X', 'tax_number' => $value]))
        ->toThrow(BusinessRuleException::class, 'Vergi no');
})->with(['123456789', '123456789012', '12345678ab']);

it('11 haneli TCKN kabul edilir', function () {
    expect(app(CustomerService::class)->create(['name' => 'Şahıs', 'phone' => '0532 000 00 00', 'tax_number' => '12345678950', 'tax_office' => 'Konak'])->tax_number)
        ->toBe('12345678950');
});

it('aynı vergi no ile ikinci müşteri oluşturulamaz (form ve veritabanı)', function () {
    $this->actingAs($this->admin);
    Customer::factory()->create(['tax_number' => '1111111114']);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Kopya', 'phone' => '1', 'tax_number' => '111 111 1114', 'tax_office' => 'X'])
        ->call('create')
        ->assertHasFormErrors(['tax_number' => 'unique']);

    expect(fn () => DB::table('customers')->insert(['name' => 'Kopya', 'phone' => '1', 'tax_number' => '1111111114', 'tax_office' => 'X']))
        ->toThrow(QueryException::class);
});

it('veritabanı müşteri kurallarını zorlar', function (array $attributes) {
    // Geçerli temel kayıt: tek başına eklenebilir; her veri kümesi yalnızca bir kuralı bozar.
    $valid = ['name' => 'X', 'phone' => '0532 000 00 00', 'tax_number' => '1234567890', 'tax_office' => 'Kadıköy'];
    DB::table('customers')->insert($valid);
    DB::table('customers')->where('name', 'X')->delete();

    expect(fn () => DB::table('customers')->insert(array_merge($valid, $attributes)))
        ->toThrow(QueryException::class);
})->with([
    'kısa vergi no' => [['tax_number' => '123']],
    'harfli vergi no' => [['tax_number' => '12345abcde']],
    'boş ad' => [['name' => ' ']],
    'telefonsuz' => [['phone' => null]],
    'boş telefon' => [['phone' => '  ']],
    'vergi dairesiz vergi no' => [['tax_office' => null]],
    'vergi nosuz vergi dairesi' => [['tax_number' => null]],
    'boş vergi dairesi' => [['tax_office' => ' ']],
]);

it('müşteri düzenlemesi iki ayrı oturumda çakışırsa ikincisi reddedilir', function () {
    $customer = Customer::factory()->create(['name' => 'Eski']);
    $other = User::factory()->admin()->create();

    $this->actingAs($this->admin);
    $sessionA = Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()]);
    $this->actingAs($other);
    $sessionB = Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()]);

    $this->actingAs($this->admin);
    $sessionA->fillForm(['name' => 'A adı'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($other);
    $sessionB->fillForm(['name' => 'B adı'])->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($customer->fresh())->name->toBe('A adı')->lock_version->toBe(1);
});

it('müşteri pasifleştirilir ve aktifleştirilir; silinemez', function () {
    $this->actingAs($this->admin);
    $customer = Customer::factory()->create();

    Livewire::test(ListCustomers::class)
        ->callAction(TestAction::make('deactivate')->table($customer))
        ->assertNotified('Müşteri pasifleştirildi');
    expect($customer->fresh()->is_active)->toBeFalse();

    Livewire::test(ListCustomers::class)
        ->filterTable('is_active', false)
        ->callAction(TestAction::make('activate')->table($customer))
        ->assertNotified('Müşteri aktifleştirildi');
    expect($customer->fresh()->is_active)->toBeTrue()
        ->and($this->admin->can('delete', $customer))->toBeFalse();
});

it('pasif müşterinin siparişleri müşteri sayfasında görünür kalır; "Yeni sipariş" gizlenir', function () {
    $this->actingAs($this->admin);
    $customer = Customer::factory()->inactive()->create();
    $order = Order::factory()->for($customer)->withItem(2)->create();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionHidden('newOrder');

    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order]);
});

it('aktif müşteride "Yeni sipariş" müşteriyi önceden seçili açar', function () {
    $this->actingAs($this->admin);
    $customer = Customer::factory()->create();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionVisible('newOrder');

    $this->get(OrderResource::getUrl('create', ['customer' => $customer->id]))
        ->assertOk()
        ->assertSee($customer->name);
});

it('operatör ve depo müşterileri yalnızca görüntüler', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $customer = Customer::factory()->create();

    $this->actingAs($user);
    $this->get(CustomerResource::getUrl('index'))->assertOk()->assertSee($customer->name);
    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk();
    $this->get(CustomerResource::getUrl('create'))->assertForbidden();
    $this->get(CustomerResource::getUrl('edit', ['record' => $customer]))->assertForbidden();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('deactivate')
        ->assertActionHidden('newOrder');
})->with(['operator', 'warehouse']);

it('telefon yaygın Türkçe biçimlerde girilebilir', function (string $phone) {
    $this->actingAs($this->admin);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'X', 'phone' => $phone])
        ->call('create')
        ->assertHasNoFormErrors();
})->with(['0 (532) 123 45 67', '+90 532 123 45 67', '0212-555-00-00', '444 1 234']);

describe('telefon zorunlu', function () {
    it('formda telefon olmadan kayıt yapılmaz', function () {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'X', 'phone' => ''])
            ->call('create')
            ->assertHasFormErrors(['phone' => 'required']);

        expect(Customer::count())->toBe(0);
    });

    it('servis telefonsuz oluşturmayı ve düzenlemede telefonu silmeyi reddeder', function () {
        expect(fn () => app(CustomerService::class)->create(['name' => 'X', 'phone' => '  ']))
            ->toThrow(BusinessRuleException::class, 'Telefon zorunludur');

        $customer = Customer::factory()->create();
        expect(fn () => app(CustomerService::class)->update($customer, ['phone' => ''], 0))
            ->toThrow(BusinessRuleException::class, 'Telefon zorunludur');
    });
});

describe('vergi no ve vergi dairesi birlikte', function () {
    it('formda biri doluysa diğeri zorunludur', function (array $data, string $missing) {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'X', 'phone' => '1', ...$data])
            ->call('create')
            ->assertHasFormErrors([$missing => 'required_with']);

        expect(Customer::count())->toBe(0);
    })->with([
        'yalnızca vergi no' => [['tax_number' => '1234567890'], 'tax_office'],
        'yalnızca vergi dairesi' => [['tax_office' => 'Kadıköy'], 'tax_number'],
    ]);

    it('ikisi birlikte veya ikisi de boş kaydedilir', function () {
        $service = app(CustomerService::class);

        expect($service->create(['name' => 'A', 'phone' => '1', 'tax_number' => '1234567890', 'tax_office' => 'Kadıköy'])->exists)->toBeTrue()
            ->and($service->create(['name' => 'B', 'phone' => '1'])->exists)->toBeTrue();
    });

    it('servis yalnızca birinin girilmesini ve düzenlemede birinin silinmesini reddeder', function () {
        $service = app(CustomerService::class);

        expect(fn () => $service->create(['name' => 'X', 'phone' => '1', 'tax_number' => '1234567890']))
            ->toThrow(BusinessRuleException::class, 'birlikte girilmelidir')
            ->and(fn () => $service->create(['name' => 'X', 'phone' => '1', 'tax_office' => 'Kadıköy']))
            ->toThrow(BusinessRuleException::class, 'birlikte girilmelidir');

        $customer = Customer::factory()->create();
        expect(fn () => $service->update($customer, ['tax_office' => ''], 0))
            ->toThrow(BusinessRuleException::class, 'birlikte girilmelidir');

        // İkisi birlikte silinebilir.
        $service->update($customer->fresh(), ['tax_number' => '', 'tax_office' => ''], 0);
        expect($customer->fresh())->tax_number->toBeNull()->tax_office->toBeNull();
    });
});

describe('aynı adlı müşteri uyarısı', function () {
    beforeEach(function () {
        $this->existing = Customer::factory()->create(['name' => 'Işık Motor Sanayi A.Ş.', 'tax_number' => '5555555553']);
        $this->service = app(CustomerService::class);
    });

    it('büyük/küçük harf (Türkçe I/ı dahil) ve baştaki/sondaki boşluklar önemsizdir', function (string $name) {
        expect($this->service->duplicatesByName($name)->pluck('id')->all())->toBe([$this->existing->id]);
    })->with(['Işık Motor Sanayi A.Ş.', '  ışık motor sanayi a.ş.  ', 'IŞIK MOTOR SANAYI A.Ş.', 'IŞIK MOTOR SANAYİ A.Ş.']);

    it('ad içindeki farklılık ve pasif müşteri aynı ad sayılmaz', function () {
        $passive = Customer::factory()->inactive()->create(['name' => 'Pasif Firma']);

        expect($this->service->duplicatesByName('Işık Motor Sanayi'))->toBeEmpty()
            ->and($this->service->duplicatesByName('Işık  Motor Sanayi A.Ş.'))->toBeEmpty()
            ->and($this->service->duplicatesByName($passive->name))->toBeEmpty();
    });

    it('servis onaysız kaydı reddeder, onayla kaydeder', function () {
        $data = ['name' => ' IŞIK MOTOR SANAYİ A.Ş. ', 'phone' => '1'];

        expect(fn () => $this->service->create($data))->toThrow(BusinessRuleException::class, 'Bu adla bir müşteri zaten var');
        expect(Customer::count())->toBe(1);

        $this->service->create($data, confirmDuplicateName: true);
        expect(Customer::count())->toBe(2);
    });

    it('formda uyarı ve onay kutusu görünür; onaysız kaydedilmez, onayla kaydedilir', function () {
        $this->actingAs($this->admin);

        $page = Livewire::test(CreateCustomer::class)
            ->assertFormFieldHidden('confirm_duplicate_name')
            ->fillForm(['name' => 'ışık motor sanayi a.ş.', 'phone' => '0232 000 00 00'])
            ->assertFormFieldVisible('confirm_duplicate_name')
            ->assertSee('Bu adla bir müşteri zaten var')
            ->assertSee('5555555553')
            ->call('create')
            ->assertHasFormErrors(['confirm_duplicate_name' => 'accepted']);

        expect(Customer::count())->toBe(1);

        $page->fillForm(['confirm_duplicate_name' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Customer::count())->toBe(2);
    });

    it('farklı adda onay kutusu çıkmaz', function () {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Başka Firma', 'phone' => '1'])
            ->assertFormFieldHidden('confirm_duplicate_name')
            ->assertDontSee('Bu adla bir müşteri zaten var')
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('düzenlemede yalnızca ad başka bir aktif müşterininkiyle aynı olacak şekilde değişirse onay istenir', function () {
        $this->actingAs($this->admin);
        $twin = $this->service->create(['name' => 'ışık motor sanayi a.ş.', 'phone' => '1'], confirmDuplicateName: true);
        $other = Customer::factory()->create(['name' => 'Başka Firma']);

        // Zaten aynı adı taşıyan kaydın başka alanı (veya adının yalnızca harf büyüklüğü) değişirse onay istenmez.
        Livewire::test(EditCustomer::class, ['record' => $twin->getRouteKey()])
            ->assertFormFieldHidden('confirm_duplicate_name')
            ->fillForm(['name' => 'Işık Motor Sanayi A.Ş.', 'phone' => '2'])
            ->assertFormFieldHidden('confirm_duplicate_name')
            ->call('save')
            ->assertHasNoFormErrors();

        // Başka bir kaydın adı aynı olacak şekilde değişirse onay istenir.
        Livewire::test(EditCustomer::class, ['record' => $other->getRouteKey()])
            ->fillForm(['name' => 'IŞIK MOTOR SANAYİ A.Ş.'])
            ->assertFormFieldVisible('confirm_duplicate_name')
            ->call('save')
            ->assertHasFormErrors(['confirm_duplicate_name' => 'accepted'])
            ->fillForm(['confirm_duplicate_name' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($other->fresh()->name)->toBe('IŞIK MOTOR SANAYİ A.Ş.');
    });

    it('servis düzenlemede onaysız ad çakışmasını reddeder', function () {
        $other = Customer::factory()->create(['name' => 'Başka Firma']);

        expect(fn () => $this->service->update($other, ['name' => 'ışık motor sanayi a.ş.'], 0))
            ->toThrow(BusinessRuleException::class, 'Bu adla bir müşteri zaten var')
            ->and($other->fresh()->name)->toBe('Başka Firma');
    });
});

describe('vergi no kontrol hanesi', function () {
    it('geçerli VKN ve TCKN formdan kaydedilir', function (string $number) {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => "Firma {$number}", 'phone' => '1', 'tax_number' => $number, 'tax_office' => 'Kadıköy'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Customer::where('tax_number', preg_replace('/\s+/', '', $number))->exists())->toBeTrue();
    })->with(['1234567890', '0012345672', '10000000146', '287 104 561 60']);

    it('kontrol hanesi hatalı numara formda Türkçe mesajla reddedilir', function (string $number) {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'X', 'phone' => '1', 'tax_number' => $number, 'tax_office' => 'Kadıköy'])
            ->call('create')
            ->assertHasFormErrors(['tax_number'])
            ->assertSee('Geçersiz vergi/TC kimlik numarası, lütfen kontrol edin.');

        expect(Customer::count())->toBe(0);
    })->with(['1234567891', '9876543210', '01234567890', '10000000147', '12345678901']);

    it('biçimi hatalı numarada yalnızca biçim mesajı gösterilir', function () {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'X', 'phone' => '1', 'tax_number' => '12345', 'tax_office' => 'Kadıköy'])
            ->call('create')
            ->assertHasFormErrors(['tax_number' => 'regex'])
            ->assertDontSee('Geçersiz vergi/TC kimlik numarası');
    });

    it('servis kontrol hanesi hatalı numarayı oluşturmada ve düzenlemede reddeder', function () {
        $service = app(CustomerService::class);

        expect(fn () => $service->create(['name' => 'X', 'phone' => '1', 'tax_number' => '1234567891', 'tax_office' => 'Kadıköy']))
            ->toThrow(BusinessRuleException::class, 'Geçersiz vergi/TC kimlik numarası, lütfen kontrol edin.');

        $customer = Customer::factory()->create();
        expect(fn () => $service->update($customer, ['tax_number' => '10000000147'], 0))
            ->toThrow(BusinessRuleException::class, 'Geçersiz vergi/TC kimlik numarası')
            ->and($customer->fresh()->tax_number)->not->toBe('10000000147');
    });

    it('veritabanı yalnızca biçimi denetler; kontrol hanesi form ve servis seviyesindedir', function () {
        DB::table('customers')->insert(['name' => 'X', 'phone' => '1', 'tax_number' => '1234567891', 'tax_office' => 'Kadıköy']);

        expect(Customer::where('tax_number', '1234567891')->exists())->toBeTrue();
    });

    it('factory geçerli vergi numaraları üretir', function () {
        Customer::factory()->count(20)->create()
            ->each(fn (Customer $c) => expect(TaxNumber::isValid($c->tax_number))->toBeTrue($c->tax_number));
    });
});
