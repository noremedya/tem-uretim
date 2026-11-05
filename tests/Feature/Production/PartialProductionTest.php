<?php

use App\Enums\MotorUnitStatus;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\MotorUnit;
use App\Models\Order;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrderProduction;
use App\Services\ProductionService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProductionFixtures;

/*
 * Kısım kısım üretim (karar 2026-11-05): üretim kaydı o adet için malzeme düşer ve motorları o günün seri
 * numarasıyla oluşturur; kalan üretilince iş emri tamamlanır; geri alma son kaydı geri alır.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->f = ProductionFixtures::create()->stockUp($this->admin);
    $this->production = app(ProductionService::class);
    $this->wo = $this->f->startedMotorWorkOrder($this->admin, 10, ['assigned_user_id' => $this->operator->id]);
});

function partialStock(Part $part): string
{
    return (string) $part->defaultStockBalance()->value('quantity');
}

it('motorlar ürettikçe kaydedilir: her kayıt kendi gününün seri numarasını alır, son kayıt iş emrini tamamlar', function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(10, 0));
    $this->production->recordProduction($this->wo, $this->operator, '4');

    expect($this->wo->fresh())->status->toBe(WorkOrderStatus::InProgress)->produced_quantity->toBe('4.000')->completed_at->toBeNull()
        ->and(partialStock($this->f->stator))->toBe('96.000')
        ->and(partialStock($this->f->bearing))->toBe('992.000')
        // Vernik 0,2 kg/motor + %5: 4 motor → 0,8 + 0,04.
        ->and(partialStock($this->f->varnish))->toBe('99.160')
        ->and(partialStock($this->f->model->finishedPart))->toBe('4.000')
        ->and(MotorUnit::query()->orderBy('id')->pluck('serial_number')->all())
        ->toBe(['TM-71-261105-00001', 'TM-71-261105-00002', 'TM-71-261105-00003', 'TM-71-261105-00004']);

    $this->travelTo(now()->setDate(2026, 11, 6)->setTime(9, 0));
    $this->production->recordProduction($this->wo, $this->operator);

    $wo = $this->wo->fresh();
    expect($wo)->status->toBe(WorkOrderStatus::Completed)->produced_quantity->toBe('10.000')->completed_at->not->toBeNull()
        // Toplam iş emrinin tamamıyla aynı: 10 stator, 20 rulman, 2 kg + 0,1 kg vernik.
        ->and(partialStock($this->f->stator))->toBe('90.000')
        ->and(partialStock($this->f->bearing))->toBe('980.000')
        ->and(partialStock($this->f->varnish))->toBe('97.900')
        ->and(partialStock($this->f->model->finishedPart))->toBe('10.000')
        ->and($wo->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBe('0.100')
        ->and(WorkOrderProduction::query()->count())->toBe(2);

    $units = MotorUnit::query()->orderBy('id')->get();
    expect($units)->toHaveCount(10)
        ->and($units->last()->serial_number)->toBe('TM-71-261106-00006')
        ->and($units->last()->production_date->toDateString())->toBe('2026-11-06')
        ->and($units->first()->production_date->toDateString())->toBe('2026-11-05')
        ->and($units->pluck('work_order_production_id')->unique())->toHaveCount(2);
});

it('kayıt miktarı pozitif, tam sayı ve kalan adet kadar olabilir', function (string $quantity, string $message) {
    $this->production->recordProduction($this->wo, $this->admin, '7');

    expect(fn () => $this->production->recordProduction($this->wo, $this->admin, $quantity))->toThrow(BusinessRuleException::class, $message);
})->with([
    'kalandan fazla' => ['4', 'Kalan 3'],
    'sıfır' => ['0', 'sıfırdan büyük'],
    'küsurat' => ['1,5', 'tam sayı'],
]);

it('bu kaydın gerçekleşen firesi girilir; yetersiz malzemede hiçbir şey değişmez', function () {
    $this->production->recordProduction($this->wo, $this->operator, '2', [$this->f->varnish->id => '0,05']);

    expect($this->wo->fresh()->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBe('0.050')
        ->and(StockMovement::query()->where('type', StockMovementType::ProductionScrap)->sole()->quantity)->toBe('-0.050');

    $before = StockMovement::query()->count();
    expect(fn () => $this->production->recordProduction($this->wo, $this->operator, '8', [$this->f->varnish->id => '200']))
        ->toThrow(BusinessRuleException::class);
    expect(StockMovement::query()->count())->toBe($before)
        ->and($this->wo->fresh()->produced_quantity)->toBe('2.000');
});

it('geri alma son kaydı geri alır; tamamlanmış iş emri üretimde durumuna döner', function () {
    $this->production->recordProduction($this->wo, $this->operator, '4');
    $this->production->recordProduction($this->wo, $this->operator, '6');

    $this->production->reverse($this->wo, $this->admin, 'Son parti hatalı');

    $wo = $this->wo->fresh();
    expect($wo)->status->toBe(WorkOrderStatus::InProgress)->produced_quantity->toBe('4.000')->completed_at->toBeNull()
        ->and(MotorUnit::query()->where('status', MotorUnitStatus::Voided->value)->count())->toBe(6)
        ->and(MotorUnit::query()->where('status', MotorUnitStatus::InStock->value)->count())->toBe(4)
        ->and(partialStock($this->f->model->finishedPart))->toBe('4.000')
        ->and(partialStock($this->f->varnish))->toBe('99.160')
        ->and($wo->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBe('0.040')
        ->and(WorkOrderProduction::query()->whereNotNull('reversed_at')->sole())
        ->quantity->toBe('6.000')->reversal_reason->toBe('Son parti hatalı');

    $this->production->reverse($this->wo, $this->admin, 'İlk kayıt da hatalı');
    expect($this->wo->fresh())->produced_quantity->toBe('0.000')
        ->and(partialStock($this->f->stator))->toBe('100.000')
        ->and($this->wo->fresh()->materials()->where('part_id', $this->f->varnish->id)->value('actual_scrap'))->toBeNull()
        ->and(fn () => $this->production->reverse($this->wo, $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'Geri alınacak üretim kaydı yok');
});

it('operatör geri alamaz; üretim kaydı olan iş emri planlamaya dönemez', function () {
    $this->production->recordProduction($this->wo, $this->operator, '3');

    expect(fn () => $this->production->reverse($this->wo, $this->operator, 'x'))->toThrow(BusinessRuleException::class, 'yalnızca yönetici')
        ->and(fn () => app(WorkOrderService::class)->revertToPlanned($this->wo->fresh(), $this->admin, 'x'))->toThrow(BusinessRuleException::class, 'planlamaya geri alınamaz');
});

it('adet üretilenin altına inemez; üretilene düşürülürse iş emri tamamlanır', function () {
    $this->production->recordProduction($this->wo, $this->operator, '6');
    $service = app(WorkOrderService::class);
    $wo = $this->wo->fresh();

    expect(fn () => $service->update($wo, ['quantity' => '5'], $wo->lock_version, $this->admin))->toThrow(BusinessRuleException::class, 'bundan az olamaz');

    $service->update($wo->fresh(), ['quantity' => '6'], $wo->fresh()->lock_version, $this->admin);

    expect($this->wo->fresh())->status->toBe(WorkOrderStatus::Completed)->quantity->toBe('6.000')
        ->and($this->wo->statusHistories()->latest('id')->value('description'))->toBe('Adet üretilen kadar düşürüldü');
});

it('sipariş kısmi kayıtta kısmi, son kayıtta tamamlandı olur', function () {
    $order = Order::factory()->open()->withItem(5, $this->f->model)->create();
    $wo = $this->f->startedMotorWorkOrder($this->admin, 5, ['order_item_id' => $order->items()->value('id')]);

    $this->production->recordProduction($wo, $this->admin, '2');
    expect($order->fresh()->status)->toBe(OrderStatus::Partial);

    $this->production->recordProduction($wo, $this->admin);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

it('üretim kaydı ve hareket bağı veritabanında değiştirilemez, silinemez', function () {
    $this->production->recordProduction($this->wo, $this->operator, '1');
    $id = WorkOrderProduction::query()->value('id');

    expect(fn () => DB::table('work_order_productions')->where('id', $id)->update(['quantity' => 2]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('work_order_productions')->where('id', $id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('work_order_production_movements')->where('work_order_production_id', $id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('work_orders')->where('id', $this->wo->id)->update(['produced_quantity' => 11]))->toThrow(QueryException::class);
});

it('ekrandan üretim kaydı girilir ve yönetici son kaydı geri alır', function () {
    $this->actingAs($this->operator);
    Livewire::test(ViewWorkOrder::class, ['record' => $this->wo->getRouteKey()])
        ->callAction('complete', data: ['quantity' => '3'])
        ->assertHasNoActionErrors()
        ->assertNotified('Üretim kaydedildi');

    expect($this->wo->fresh()->produced_quantity)->toBe('3.000');

    Livewire::test(ViewWorkOrder::class, ['record' => $this->wo->getRouteKey()])->assertActionHidden('reverse');

    $this->actingAs($this->admin);
    Livewire::test(ViewWorkOrder::class, ['record' => $this->wo->getRouteKey()])
        ->assertActionHidden('revertToPlanned')
        ->callAction('reverse', data: ['reason' => 'Yanlış adet'])
        ->assertNotified('Üretim kaydı geri alındı');

    expect($this->wo->fresh()->produced_quantity)->toBe('0.000');
});
