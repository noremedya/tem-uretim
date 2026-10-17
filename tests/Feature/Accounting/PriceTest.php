<?php

use App\Enums\Currency;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Prices\Pages\CreatePrice;
use App\Filament\Resources\Prices\Pages\ListPrices;
use App\Filament\Resources\Prices\PriceResource;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Price;
use App\Models\User;
use App\Services\PriceService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(PriceService::class);
    $this->model = MotorModel::factory()->create();
    $this->customer = Customer::factory()->create();
});

function priceData(array $data = []): array
{
    return ['motor_model_id' => test()->model->id, 'currency' => 'TRY', 'unit_price' => '12.500,00', 'valid_from' => today(), ...$data];
}

it('liste ve müşteri fiyatı girilir', function () {
    $list = $this->service->create(priceData());
    $special = $this->service->create(priceData(['customer_id' => $this->customer->id, 'currency' => 'EUR', 'unit_price' => '300']));

    expect($list)->unit_price->toBe('12500.00')->customer_id->toBeNull()->currency->toBe(Currency::TRY)
        ->and($special)->unit_price->toBe('300.00')->currency->toBe(Currency::EUR);
});

it('geçmiş tarih, pasif model, tedarikçi ve aynı gün ikinci fiyat reddedilir', function (Closure $data, string $message) {
    $this->service->create(priceData());

    expect(fn () => $this->service->create($data()))->toThrow(BusinessRuleException::class, $message);
})->with([
    'geçmiş tarih' => [fn () => priceData(['valid_from' => today()->subDay()]), 'Geçmiş tarihli'],
    'pasif model' => [fn () => priceData(['motor_model_id' => MotorModel::factory()->inactive()->create()->id]), 'aktif motor modeline'],
    'tedarikçi' => [fn () => priceData(['customer_id' => Customer::factory()->create(['is_customer' => false, 'is_supplier' => true])->id]), 'müşteri işaretli'],
    'aynı gün liste fiyatı' => [fn () => priceData(), 'zaten var'],
    'sıfır fiyat' => [fn () => priceData(['unit_price' => '0', 'valid_from' => today()->addDay()]), 'sıfırdan büyük'],
]);

it('veritabanı aynı gün için tek liste fiyatına ve müşteri türüne izin verir', function () {
    $this->service->create(priceData());
    $row = ['motor_model_id' => $this->model->id, 'currency' => 'TRY', 'unit_price' => 1, 'valid_from' => today(), 'created_at' => now(), 'updated_at' => now()];
    $supplier = Customer::factory()->create(['is_customer' => false, 'is_supplier' => true]);

    expect(fn () => DB::transaction(fn () => DB::table('prices')->insert($row)))->toThrow(QueryException::class, 'prices_model_customer_from_unique')
        ->and(fn () => DB::transaction(fn () => DB::table('prices')->insert([...$row, 'customer_id' => $supplier->id])))->toThrow(QueryException::class, 'müşteri olarak işaretli değil');
});

it('etkin fiyat: önce müşteri fiyatı, yoksa liste fiyatı; tarihe kadar başlamış en son kayıt', function () {
    $this->service->create(priceData(['unit_price' => 100]));
    $this->service->create(priceData(['unit_price' => 110, 'valid_from' => today()->addDays(10)]));
    $this->service->create(priceData(['unit_price' => 90, 'customer_id' => $this->customer->id, 'valid_from' => today()->addDays(5)]));
    $other = Customer::factory()->create();

    expect($this->service->priceFor($this->model, $this->customer)->unit_price)->toBe('100.00')
        ->and($this->service->priceFor($this->model, $this->customer, today()->addDays(6))->unit_price)->toBe('90.00')
        ->and($this->service->priceFor($this->model, $this->customer, today()->addDays(11))->unit_price)->toBe('90.00')
        ->and($this->service->priceFor($this->model, $other, today()->addDays(11))->unit_price)->toBe('110.00')
        ->and($this->service->priceFor($this->model, null, today()->subDay()))->toBeNull();
});

it('yalnızca ileri tarihli fiyat düzenlenir ve silinir', function () {
    $started = $this->service->create(priceData());
    $future = $this->service->create(priceData(['valid_from' => today()->addWeek(), 'unit_price' => 200]));

    expect(fn () => $this->service->update($started, ['unit_price' => 1], $started->lock_version))->toThrow(BusinessRuleException::class, 'Geçerliliği başlamış')
        ->and(fn () => $this->service->delete($started))->toThrow(BusinessRuleException::class, 'Geçerliliği başlamış')
        ->and($this->admin->can('update', $started))->toBeFalse()
        ->and($this->admin->can('update', $future))->toBeTrue();

    $this->service->update($future, ['unit_price' => '250,50'], $future->lock_version);
    expect($future->fresh()->unit_price)->toBe('250.50');

    $this->service->delete($future->fresh());
    expect(Price::count())->toBe(1);
});

it('ekrandan fiyat girilir; muhasebe yönetir, depo erişemez', function () {
    $accountant = User::factory()->withRoles(Role::Accounting)->create();
    $this->actingAs($accountant);

    Livewire::test(CreatePrice::class)
        ->fillForm(['motor_model_id' => $this->model->id, 'customer_id' => $this->customer->id, 'currency' => 'USD', 'unit_price' => '1.250,75', 'valid_from' => today()->toDateString()])
        ->call('create')
        ->assertHasNoFormErrors();

    $price = Price::sole();
    expect($price)->unit_price->toBe('1250.75')->currency->toBe(Currency::USD);

    Livewire::test(ListPrices::class)
        ->assertCanSeeTableRecords([$price])
        ->assertActionHidden(TestAction::make('edit')->table($price))
        ->assertActionHidden(TestAction::make('delete')->table($price));

    $this->actingAs(User::factory()->warehouse()->create());
    $this->get(PriceResource::getUrl('index'))->assertForbidden();
});
