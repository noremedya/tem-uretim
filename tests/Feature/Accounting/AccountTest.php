<?php

use App\Enums\Currency;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(CustomerService::class);
});

function accountData(array $data = []): array
{
    return ['name' => 'Akın Bobinaj Ltd.', 'phone' => '0212 555 00 00', ...$data];
}

it('cari müşteri, tedarikçi veya ikisi birden olur; varsayılanlar müşteri, vade 0, TRY', function () {
    $default = $this->service->create(accountData());
    $supplier = $this->service->create(accountData(['name' => 'Tel Tedarik A.Ş.', 'is_customer' => false, 'is_supplier' => true, 'payment_term_days' => '60', 'default_currency' => 'EUR']));

    expect($default)->is_customer->toBeTrue()->is_supplier->toBeFalse()->payment_term_days->toBe(0)->default_currency->toBe(Currency::TRY)
        ->and($supplier->fresh())->is_customer->toBeFalse()->is_supplier->toBeTrue()->payment_term_days->toBe(60)->default_currency->toBe(Currency::EUR);

    expect(fn () => $this->service->create(accountData(['name' => 'Hiçbiri', 'is_customer' => false, 'is_supplier' => false])))
        ->toThrow(BusinessRuleException::class, 'müşteri, tedarikçi veya ikisi birden')
        ->and(fn () => $this->service->create(accountData(['name' => 'Uzun vade', 'payment_term_days' => 400])))
        ->toThrow(BusinessRuleException::class, '0 ile 365');
});

it('veritabanı cari türü, vade ve para birimini zorlar', function (array $values) {
    $customer = Customer::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('customers')->where('id', $customer->id)->update($values)))->toThrow(QueryException::class);
})->with([
    'türsüz' => [['is_customer' => false, 'is_supplier' => false]],
    'vade' => [['payment_term_days' => 366]],
    'para birimi' => [['default_currency' => 'GBP']],
]);

it('tedarikçiye sipariş açılamaz (servis ve veritabanı)', function () {
    $supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);
    $model = MotorModel::factory()->create();

    expect(fn () => app(OrderService::class)->create([
        'customer_id' => $supplier->id, 'due_date' => today()->addWeek(), 'items' => [['motor_model_id' => $model->id, 'quantity' => 1]],
    ]))->toThrow(BusinessRuleException::class, 'müşteri olarak işaretli değil');

    $order = Order::factory()->open()->create();
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update(['customer_id' => $supplier->id])))
        ->toThrow(QueryException::class, 'müşteri olarak işaretli değil');
});

it('siparişi olan carinin müşteri işareti kaldırılamaz', function () {
    $order = Order::factory()->open()->create();
    $customer = $order->customer;

    expect(fn () => $this->service->update($customer, ['is_customer' => false, 'is_supplier' => true], $customer->lock_version))
        ->toThrow(BusinessRuleException::class, 'müşteri işareti kaldırılamaz');

    // Tedarikçi işareti eklemek serbest.
    $this->service->update($customer->fresh(), ['is_supplier' => true], $customer->fresh()->lock_version);
    expect($customer->fresh())->is_customer->toBeTrue()->is_supplier->toBeTrue();
});

it('formdan tedarikçi açılır; listede türe göre filtrelenir', function () {
    $this->actingAs($this->admin);
    $customer = Customer::factory()->create();

    Livewire::test(CreateCustomer::class)
        ->fillForm([...accountData(['name' => 'Rulman Dünyası']), 'is_customer' => false, 'is_supplier' => true, 'payment_term_days' => 30, 'default_currency' => 'USD'])
        ->call('create')
        ->assertHasNoFormErrors();

    $supplier = Customer::where('name', 'Rulman Dünyası')->sole();
    expect($supplier)->is_supplier->toBeTrue()->default_currency->toBe(Currency::USD);

    Livewire::test(ListCustomers::class)
        ->filterTable('kind', 'supplier')
        ->assertCanSeeTableRecords([$supplier])
        ->assertCanNotSeeTableRecords([$customer]);

    Livewire::test(CreateCustomer::class)
        ->fillForm([...accountData(['name' => 'Türsüz']), 'is_customer' => false, 'is_supplier' => false])
        ->call('create')
        ->assertHasFormErrors(['is_supplier']);
});

it('sipariş formunda yalnızca müşteri işaretli cariler çıkar', function () {
    $this->actingAs($this->admin);
    $customer = Customer::factory()->create(['name' => 'Alıcı']);
    $supplier = Customer::factory()->create(['name' => 'Satıcı', 'is_customer' => false, 'is_supplier' => true]);

    $options = Livewire::test(CreateOrder::class)->instance()->form->getComponent('customer_id')->getOptions();

    expect($options)->toHaveKey($customer->id)->not->toHaveKey($supplier->id);
});

it('muhasebe rolü cari yönetir, siparişi görür; üretim ve stok işlemi yapamaz', function () {
    $accountant = User::factory()->withRoles(Role::Accounting)->create();

    expect($accountant->can(Permission::CustomersManage))->toBeTrue()
        ->and($accountant->can(Permission::PricesManage))->toBeTrue()
        ->and($accountant->can(Permission::ExchangeRatesManage))->toBeTrue()
        ->and($accountant->can(Permission::OrdersManage))->toBeFalse()
        ->and($accountant->can(Permission::StockMove))->toBeFalse()
        ->and($accountant->can(Permission::WorkOrdersView))->toBeFalse();

    $this->actingAs($accountant);
    $this->get(OrderResource::getUrl('index'))->assertOk();
    $this->get(OrderResource::getUrl('create'))->assertForbidden();
    $this->get(WorkOrderResource::getUrl('index'))->assertForbidden();
});
