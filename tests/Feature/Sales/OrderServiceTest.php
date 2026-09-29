<?php

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Models\Customer;
use App\Models\MotorModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function orderData(array $overrides = []): array
{
    return array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'order_date' => '2026-09-29',
        'due_date' => '2026-10-31',
        'items' => [['motor_model_id' => MotorModel::factory()->create()->id, 'quantity' => 5]],
    ], $overrides);
}

beforeEach(function () {
    $this->service = app(OrderService::class);
});

it('sipariş kalemleriyle oluşturulur; numara otomatik, durum açık', function () {
    $this->travelTo('2026-09-29 10:00');
    $modelA = MotorModel::factory()->create();
    $modelB = MotorModel::factory()->create();

    $order = $this->service->create(orderData([
        'customer_reference' => ' PO-778 ',
        'notes' => '',
        'items' => [
            ['motor_model_id' => $modelA->id, 'quantity' => '10'],
            ['motor_model_id' => (string) $modelB->id, 'quantity' => 3],
        ],
    ]));

    expect($order->fresh())
        ->order_number->toBe('SIP-2026-00001')
        ->status->toBe(OrderStatus::Open)
        ->customer_reference->toBe('PO-778')
        ->notes->toBeNull()
        ->lock_version->toBe(0)
        ->and($order->items()->pluck('quantity', 'motor_model_id')->all())->toBe([$modelA->id => 10, $modelB->id => 3])
        ->and($this->service->create(orderData())->order_number)->toBe('SIP-2026-00002');
});

it('sipariş tarihi verilmezse bugündür', function () {
    $this->travelTo('2026-03-15 09:00');

    $order = $this->service->create(orderData(['order_date' => null, 'due_date' => '2026-04-01']));

    expect($order->fresh()->order_date->toDateString())->toBe('2026-03-15');
});

it('geçersiz siparişler reddedilir ve hiçbir şey kaydedilmez', function (Closure $data, string $message) {
    expect(fn () => $this->service->create($data()))->toThrow(BusinessRuleException::class, $message);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(DB::table('number_sequences')->count())->toBe(0);
})->with([
    'kalemsiz' => [fn () => orderData(['items' => []]), 'en az bir kalem'],
    'sıfır miktar' => [fn () => orderData(['items' => [['motor_model_id' => MotorModel::factory()->create()->id, 'quantity' => 0]]]), 'pozitif bir tam sayı'],
    'küsuratlı miktar' => [fn () => orderData(['items' => [['motor_model_id' => MotorModel::factory()->create()->id, 'quantity' => '1.5']]]), 'pozitif bir tam sayı'],
    'aynı model iki kez' => [function () {
        $id = MotorModel::factory()->create()->id;

        return orderData(['items' => [['motor_model_id' => $id, 'quantity' => 1], ['motor_model_id' => $id, 'quantity' => 2]]]);
    }, 'birden fazla kalem'],
    'termin yok' => [fn () => orderData(['due_date' => null]), 'Termin tarihi zorunludur'],
    'termin sipariş tarihinden önce' => [fn () => orderData(['order_date' => '2026-09-29', 'due_date' => '2026-09-28']), 'önce olamaz'],
    'pasif müşteri' => [fn () => orderData(['customer_id' => Customer::factory()->inactive()->create()->id]), 'Pasif müşteriye'],
    'pasif motor modeli' => [fn () => orderData(['items' => [['motor_model_id' => MotorModel::factory()->inactive()->create(['code' => 'ESKI-1'])->id, 'quantity' => 1]]]), 'ESKI-1'],
    'olmayan motor modeli' => [fn () => orderData(['items' => [['motor_model_id' => 999999, 'quantity' => 1]]]), 'bulunamadı'],
]);

it('termin sipariş tarihiyle aynı gün olabilir', function () {
    expect($this->service->create(orderData(['order_date' => '2026-09-29', 'due_date' => '2026-09-29']))->exists)->toBeTrue();
});

it('kalemler güncellenir: miktar değişir, yeni eklenir, çıkarılan silinir; değişmeyen kalemin kimliği korunur', function () {
    [$keep, $change, $remove, $add] = MotorModel::factory()->count(4)->create();
    $order = $this->service->create(orderData(['items' => [
        ['motor_model_id' => $keep->id, 'quantity' => 1],
        ['motor_model_id' => $change->id, 'quantity' => 2],
        ['motor_model_id' => $remove->id, 'quantity' => 3],
    ]]));
    $keptItemId = $order->items()->where('motor_model_id', $keep->id)->value('id');

    $this->service->update($order, ['items' => [
        ['motor_model_id' => $keep->id, 'quantity' => 1],
        ['motor_model_id' => $change->id, 'quantity' => 20],
        ['motor_model_id' => $add->id, 'quantity' => 4],
    ]], 0);

    expect($order->items()->pluck('quantity', 'motor_model_id')->all())
        ->toEqualCanonicalizing([$keep->id => 1, $change->id => 20, $add->id => 4])
        ->and($order->items()->where('motor_model_id', $keep->id)->value('id'))->toBe($keptItemId)
        ->and($order->fresh()->lock_version)->toBe(1);
});

it('yalnızca kalem değişikliği de siparişin sürümünü artırır ve çakışmayı yakalar', function () {
    $order = $this->service->create(orderData());
    $modelId = $order->items()->value('motor_model_id');

    $this->service->update($order, ['items' => [['motor_model_id' => $modelId, 'quantity' => 9]]], 0);

    expect($order->fresh()->lock_version)->toBe(1)
        ->and(fn () => $this->service->update(Order::find($order->id), ['items' => [['motor_model_id' => $modelId, 'quantity' => 1]]], 0))
        ->toThrow(StaleModelException::class)
        ->and($order->items()->value('quantity'))->toBe(9);
});

it('siparişte zaten olan pasif model korunur ama yeni kalem olarak eklenemez', function () {
    $model = MotorModel::factory()->create();
    $order = $this->service->create(orderData(['items' => [['motor_model_id' => $model->id, 'quantity' => 2]]]));
    $model->is_active = false;
    $model->save();

    // Mevcut kalemin miktarı değişebilir.
    $this->service->update($order, ['items' => [['motor_model_id' => $model->id, 'quantity' => 5]]], 0);
    expect($order->items()->value('quantity'))->toBe(5);

    // Çıkarılıp yeniden eklenemez.
    $other = MotorModel::factory()->create();
    $this->service->update($order->fresh(), ['items' => [['motor_model_id' => $other->id, 'quantity' => 1]]], 1);
    expect(fn () => $this->service->update($order->fresh(), ['items' => [['motor_model_id' => $model->id, 'quantity' => 1]]], 2))
        ->toThrow(BusinessRuleException::class, 'Pasif motor modeli');
});

it('sipariş pasif bir müşteriye taşınamaz; pasifleşen mevcut müşteriyle düzenleme sürer', function () {
    $order = $this->service->create(orderData());
    $customer = Customer::find($order->customer_id);
    $customer->is_active = false;
    $customer->save();

    // Müşteri değişmeden düzenleme yapılabilir.
    $this->service->update($order, ['customer_id' => $customer->id, 'notes' => 'Not'], 0);
    expect($order->fresh()->notes)->toBe('Not');

    $inactive = Customer::factory()->inactive()->create();
    expect(fn () => $this->service->update($order->fresh(), ['customer_id' => $inactive->id], 1))
        ->toThrow(BusinessRuleException::class, 'Pasif müşteriye');
});

it('kısmi siparişte yalnızca termin, müşteri sipariş no ve notlar değişir', function () {
    $order = Order::factory()->partial()->withItem(5)->create();
    $item = $order->items()->sole();

    $this->service->update($order, [
        'due_date' => $order->due_date->addDays(10)->toDateString(),
        'customer_reference' => 'PO-1',
        'notes' => 'Termin uzatıldı',
        // Değişmeyen değerler gönderilebilir.
        'customer_id' => $order->customer_id,
        'items' => [['motor_model_id' => $item->motor_model_id, 'quantity' => 5]],
    ], 0);

    expect($order->fresh())->customer_reference->toBe('PO-1')->notes->toBe('Termin uzatıldı');

    $changes = [
        ['customer_id' => Customer::factory()->create()->id],
        ['order_date' => $order->order_date->subDay()->toDateString()],
        ['items' => [['motor_model_id' => $item->motor_model_id, 'quantity' => 6]]],
    ];

    foreach ($changes as $change) {
        expect(fn () => $this->service->update($order->fresh(), $change, $order->fresh()->lock_version))
            ->toThrow(BusinessRuleException::class, 'Kısmi siparişte');
    }

    expect($item->fresh()->quantity)->toBe(5);
});

it('tamamlanan ve iptal edilen sipariş düzenlenemez', function (string $state) {
    $order = Order::factory()->{$state}()->withItem()->create();

    expect(fn () => $this->service->update($order, ['notes' => 'x'], 0))
        ->toThrow(BusinessRuleException::class, 'düzenlenemez');
})->with(['completed', 'cancelled']);

it('açık ve kısmi sipariş açıklamayla iptal edilir; açıklama ve denetim izi yazılır', function (string $state) {
    $order = Order::factory()->{$state}()->withItem()->create();

    $cancelled = $this->service->cancel($order, '  Müşteri siparişi geri çekti  ');

    expect($cancelled)
        ->status->toBe(OrderStatus::Cancelled)
        ->cancellation_reason->toBe('Müşteri siparişi geri çekti')
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    $activity = Activity::where('subject_type', 'order')->where('subject_id', $order->id)->where('event', 'updated')->latest('id')->first();
    expect($activity->attribute_changes['attributes'])
        ->toMatchArray(['status' => 'cancelled', 'cancellation_reason' => 'Müşteri siparişi geri çekti']);
})->with(['open', 'partial']);

it('iptal açıklaması zorunludur', function (?string $reason) {
    $order = Order::factory()->withItem()->create();

    expect(fn () => $this->service->cancel($order, $reason))->toThrow(BusinessRuleException::class, 'açıklaması zorunludur')
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);
})->with([null, '', '   ']);

it('tamamlanan sipariş iptal edilemez; iptal geri alınamaz', function () {
    $completed = Order::factory()->completed()->withItem()->create();
    $cancelled = Order::factory()->cancelled()->withItem()->create();

    expect(fn () => $this->service->cancel($completed, 'x'))->toThrow(BusinessRuleException::class, 'geçilemez')
        ->and(fn () => $this->service->cancel($cancelled, 'x'))->toThrow(BusinessRuleException::class, 'geçilemez');
});

it('iptal, eski kopyayla açılmış düzenlemeyi çakışma olarak reddettirir', function () {
    $order = $this->service->create(orderData());
    $staleCopy = Order::find($order->id);

    $this->service->cancel($order, 'Vazgeçildi');

    expect(fn () => $this->service->update($staleCopy, ['notes' => 'x'], 0))->toThrow(StaleModelException::class);
});

it('izinli durum geçişleri enum tablosuyla sınırlıdır', function () {
    expect(OrderStatus::Open->canTransitionTo(OrderStatus::Partial))->toBeTrue()
        ->and(OrderStatus::Open->canTransitionTo(OrderStatus::Completed))->toBeTrue()
        ->and(OrderStatus::Partial->canTransitionTo(OrderStatus::Completed))->toBeTrue()
        ->and(OrderStatus::Partial->canTransitionTo(OrderStatus::Open))->toBeFalse()
        ->and(OrderStatus::Completed->allowedTransitions())->toBe([])
        ->and(OrderStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(fn () => OrderStatus::Cancelled->ensureCanTransitionTo(OrderStatus::Open))
        ->toThrow(BusinessRuleException::class, '"İptal" iken "Açık"');
});

it('sipariş ve kalem değişiklikleri denetim izine yazılır', function () {
    $order = $this->service->create(orderData());

    expect(Activity::where('subject_type', 'order')->where('subject_id', $order->id)->exists())->toBeTrue()
        ->and(Activity::where('subject_type', 'order_item')->where('event', 'created')->count())->toBe(1);
});

describe('veritabanı kısıtları', function () {
    beforeEach(function () {
        $this->order = Order::factory()->withItem()->create();
    });

    it('kalem miktarı pozitif olmalı, aynı model tek kalem olmalı', function () {
        $item = $this->order->items()->sole();

        expect(fn () => DB::table('order_items')->where('id', $item->id)->update(['quantity' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->insert(['order_id' => $this->order->id, 'motor_model_id' => $item->motor_model_id, 'quantity' => 1]))
            ->toThrow(QueryException::class);
    });

    it('termin sipariş tarihinden önce olamaz', function () {
        expect(fn () => DB::table('orders')->where('id', $this->order->id)->update(['due_date' => $this->order->order_date->subDay()]))
            ->toThrow(QueryException::class);
    });

    it('sipariş numarası benzersiz ve dolu olmalı', function () {
        $other = Order::factory()->create();

        expect(fn () => DB::table('orders')->where('id', $other->id)->update(['order_number' => $this->order->order_number]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', $other->id)->update(['order_number' => ' ']))->toThrow(QueryException::class);
    });

    it('iptal durumu ile iptal açıklaması birlikte olmalı; geçersiz durum reddedilir', function (array $values) {
        expect(fn () => DB::table('orders')->where('id', $this->order->id)->update($values))->toThrow(QueryException::class);
    })->with([
        'açıklamasız iptal' => [['status' => 'cancelled']],
        'boş açıklamalı iptal' => [['status' => 'cancelled', 'cancellation_reason' => ' ']],
        'iptal olmayan siparişte açıklama' => [['cancellation_reason' => 'x']],
        'geçersiz durum' => [['status' => 'shipped']],
    ]);

    it('siparişi veya kalemi olan müşteri ve motor modeli silinemez', function () {
        $item = $this->order->items()->sole();

        expect(fn () => DB::table('customers')->where('id', $this->order->customer_id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('motor_models')->where('id', $item->motor_model_id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', $this->order->id)->delete())->toThrow(QueryException::class);
    });
});
