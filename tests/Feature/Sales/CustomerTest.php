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

it('yalnızca ad zorunludur', function () {
    $customer = app(CustomerService::class)->create(['name' => 'Şahıs', 'tax_number' => '', 'phone' => ' ']);

    expect($customer->fresh())->tax_number->toBeNull()->phone->toBeNull();
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
    expect(app(CustomerService::class)->create(['name' => 'Şahıs', 'tax_number' => '12345678901'])->tax_number)
        ->toBe('12345678901');
});

it('aynı vergi no ile ikinci müşteri oluşturulamaz (form ve veritabanı)', function () {
    $this->actingAs($this->admin);
    Customer::factory()->create(['tax_number' => '1111111111']);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Kopya', 'tax_number' => '111 111 1111'])
        ->call('create')
        ->assertHasFormErrors(['tax_number' => 'unique']);

    expect(fn () => DB::table('customers')->insert(['name' => 'Kopya', 'tax_number' => '1111111111']))
        ->toThrow(QueryException::class);
});

it('veritabanı vergi no biçimini ve boş adı reddeder', function (array $attributes) {
    expect(fn () => DB::table('customers')->insert(array_merge(['name' => 'X'], $attributes)))
        ->toThrow(QueryException::class);
})->with([
    'kısa vergi no' => [['tax_number' => '123']],
    'harfli vergi no' => [['tax_number' => '12345abcde']],
    'boş ad' => [['name' => ' ']],
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
