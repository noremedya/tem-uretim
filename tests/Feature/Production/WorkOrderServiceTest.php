<?php

use App\Enums\PartType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Models\MotorModel;
use App\Models\Order;
use App\Models\Part;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusHistory;
use App\Services\BomService;
use App\Services\OrderService;
use App\Services\PartService;
use App\Services\WorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->operator()->create();
    $this->otherOperator = User::factory()->operator()->create();
    $this->warehouse = User::factory()->warehouse()->create();
    $this->f = ProductionFixtures::create();
    $this->service = app(WorkOrderService::class);
});

it('mamul iş emri oluşturur: numara, model, malzeme listesi ve geçmiş', function () {
    $this->actingAs($this->admin);
    $wo = $this->f->motorWorkOrder($this->admin, 10, ['assigned_user_id' => $this->operator->id, 'planned_start' => today()]);

    expect($wo)
        ->work_order_number->toBe('IE-'.now()->year.'-00001')
        ->status->toBe(WorkOrderStatus::Planned)
        ->motor_model_id->toBe($this->f->model->id)
        ->quantity->toBe('10.000')
        ->assigned_user_id->toBe($this->operator->id);

    $materials = $wo->materials()->get()->keyBy('part_id');
    expect($materials)->toHaveCount(3)
        ->and($materials[$this->f->stator->id]->required_quantity)->toBe('10.000')
        ->and($materials[$this->f->bearing->id]->required_quantity)->toBe('20.000')
        ->and($materials[$this->f->bearing->id]->planned_scrap)->toBe('0.000')
        // 10 × 0,2 = 2 kg; %5 fire = 0,1 kg.
        ->and($materials[$this->f->varnish->id]->required_quantity)->toBe('2.000')
        ->and($materials[$this->f->varnish->id]->planned_scrap)->toBe('0.100');

    $history = WorkOrderStatusHistory::where('work_order_id', $wo->id)->sole();
    expect($history)->from_status->toBeNull()->to_status->toBe(WorkOrderStatus::Planned)->user_id->toBe($this->admin->id);

    expect(Activity::where('subject_type', 'work_order')->where('subject_id', $wo->id)->where('event', 'created')->exists())->toBeTrue();
});

it('yarı mamul iş emri oluşturur (modelsiz)', function () {
    $wo = $this->f->statorWorkOrder($this->admin, 4);

    expect($wo->motor_model_id)->toBeNull()
        // 4 × 1,25 = 5 kg sac, %4 fire = 0,2 kg.
        ->and($wo->materials()->where('part_id', $this->f->sheet->id)->sole())
        ->required_quantity->toBe('5.000')
        ->planned_scrap->toBe('0.200');
});

it('tam sayılı birimde standart fire yukarı yuvarlanır', function () {
    expect($this->service->standardScrap('4.000', '2.00', wholeUnit: true))->toBe('1.000')
        ->and($this->service->standardScrap('100.000', '2.00', wholeUnit: true))->toBe('2.000')
        ->and($this->service->standardScrap('4.000', '0.00', wholeUnit: true))->toBe('0.000')
        ->and($this->service->standardScrap('1.000', '3.33', wholeUnit: false))->toBe('0.034');
});

it('reçetesiz, reçetesinde pasif bileşen olan veya üretilemeyen karta iş emri açılmaz', function () {
    $noBom = Part::factory()->create(['type' => PartType::SemiFinished]);
    expect(fn () => $this->service->create(['product_part_id' => $noBom->id, 'quantity' => 1, 'planned_end' => today()], $this->admin))
        ->toThrow(BusinessRuleException::class, 'reçete tanımlı değil');

    expect(fn () => $this->service->create(['product_part_id' => $this->f->sheet->id, 'quantity' => 1, 'planned_end' => today()], $this->admin))
        ->toThrow(BusinessRuleException::class, 'yalnızca yarı mamul veya mamul');

    app(PartService::class)->deactivate($this->f->copper);
    expect(fn () => $this->f->statorWorkOrder($this->admin))->toThrow(BusinessRuleException::class, 'pasif bileşen var');

    expect(WorkOrder::count())->toBe(0);
});

it('adet, birim ve tarihler doğrulanır', function (array $data, string $message) {
    expect(fn () => $this->f->motorWorkOrder($this->admin, ...$data))->toThrow(BusinessRuleException::class, $message);
})->with([
    'sıfır adet' => [[0], 'sıfırdan büyük'],
    'küsuratlı motor adedi' => [['1,5'], 'tam sayı olmalıdır'],
    'planlanan bitiş yok' => [[1, ['planned_end' => null]], 'Planlanan bitiş tarihi zorunludur'],
    'bitiş başlangıçtan önce' => [[1, ['planned_start' => today()->addDays(10)]], 'başlangıçtan önce olamaz'],
]);

it('yalnızca aktif saha operatörü atanabilir', function (Closure $user) {
    expect(fn () => $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $user()->id]))
        ->toThrow(BusinessRuleException::class, 'Yalnızca aktif saha operatörleri atanabilir');
})->with([
    'depo' => [fn () => User::factory()->warehouse()->create()],
    'pasif operatör' => [fn () => User::factory()->operator()->inactive()->create()],
]);

it('veritabanı ürün kurallarını zorlar', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 1);

    expect(fn () => DB::transaction(fn () => DB::table('work_orders')->where('id', $wo->id)->update(['product_part_id' => $this->f->stator->id])))
        ->toThrow(QueryException::class, 'Mamul iş emri bir motor modeline bağlı olmalıdır')
        ->and(fn () => DB::transaction(fn () => DB::table('work_orders')->where('id', $wo->id)->update(['status' => 'cancelled'])))
        ->toThrow(QueryException::class, 'work_orders_cancellation_check');
});

it('durum geçmişi değiştirilemez ve silinemez', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 1);
    $history = WorkOrderStatusHistory::where('work_order_id', $wo->id)->sole();

    expect(fn () => $history->delete())->toThrow(BusinessRuleException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('work_order_status_histories')->delete()))->toThrow(QueryException::class, 'değiştirilemez veya silinemez')
        ->and(fn () => DB::transaction(fn () => DB::table('work_order_status_histories')->update(['description' => 'x'])))->toThrow(QueryException::class);
});

describe('durum geçişleri', function () {
    beforeEach(function () {
        $this->wo = $this->f->motorWorkOrder($this->admin, 2, ['assigned_user_id' => $this->operator->id]);
    });

    it('atanan operatör başlatır; başlangıç zamanı ve geçmiş yazılır', function () {
        $this->service->start($this->wo, $this->operator);

        expect($this->wo->fresh())->status->toBe(WorkOrderStatus::InProgress)->started_at->not->toBeNull()
            ->and(WorkOrderStatusHistory::where('work_order_id', $this->wo->id)->latest('id')->first())
            ->from_status->toBe(WorkOrderStatus::Planned)->to_status->toBe(WorkOrderStatus::InProgress)->user_id->toBe($this->operator->id);
    });

    it('operatör başkasının iş emrini değiştiremez; depo hiç değiştiremez', function (string $who) {
        expect(fn () => $this->service->start($this->wo, $this->{$who}))->toThrow(BusinessRuleException::class, 'yetkiniz yok');

        expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Planned);
    })->with(['otherOperator', 'warehouse']);

    it('atanmamış iş emrini yalnızca yönetici başlatır', function () {
        $unassigned = $this->f->motorWorkOrder($this->admin, 1);

        expect(fn () => $this->service->start($unassigned, $this->operator))->toThrow(BusinessRuleException::class, 'yetkiniz yok');

        $this->service->start($unassigned, $this->admin);
        expect($unassigned->fresh()->status)->toBe(WorkOrderStatus::InProgress);
    });

    it('planlamaya geri alma yalnızca yöneticidir ve açıklama zorunludur', function () {
        $this->service->start($this->wo, $this->operator);

        expect(fn () => $this->service->revertToPlanned($this->wo, $this->operator, 'Neden'))->toThrow(BusinessRuleException::class, 'yetkiniz yok')
            ->and(fn () => $this->service->revertToPlanned($this->wo, $this->admin, ' '))->toThrow(BusinessRuleException::class, 'açıklaması zorunludur');

        $this->service->revertToPlanned($this->wo, $this->admin, 'Malzeme bekleniyor');

        expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Planned)
            ->and(WorkOrderStatusHistory::where('work_order_id', $this->wo->id)->latest('id')->first()->description)->toBe('Malzeme bekleniyor');
    });

    it('iptal yalnızca planlandıda, yöneticiyle ve açıklamayla yapılır; iptal son durumdur', function () {
        expect(fn () => $this->service->cancel($this->wo, $this->operator, 'x'))->toThrow(BusinessRuleException::class, 'yetkiniz yok')
            ->and(fn () => $this->service->cancel($this->wo, $this->admin, ''))->toThrow(BusinessRuleException::class, 'İptal açıklaması zorunludur');

        $this->service->cancel($this->wo, $this->admin, 'Sipariş değişti');

        expect($this->wo->fresh())->status->toBe(WorkOrderStatus::Cancelled)->cancellation_reason->toBe('Sipariş değişti')
            ->and(fn () => $this->service->start($this->wo, $this->admin))->toThrow(BusinessRuleException::class, 'geçilemez');
    });

    it('üretimdeki iş emri iptal edilemez', function () {
        $this->service->start($this->wo, $this->admin);

        expect(fn () => $this->service->cancel($this->wo, $this->admin, 'x'))->toThrow(BusinessRuleException::class, '"Üretimde" iken "İptal" durumuna geçilemez');
    });

    it('başlatma iki kez yapılamaz (kilit altında güncel durum okunur)', function () {
        $stale = WorkOrder::find($this->wo->id);
        $this->service->start($this->wo, $this->operator);

        expect(fn () => $this->service->start($stale, $this->operator))->toThrow(BusinessRuleException::class, 'geçilemez');
        expect(WorkOrderStatusHistory::where('work_order_id', $this->wo->id)->count())->toBe(2);
    });
});

it('düzenleme: adet değişince malzeme ihtiyacı yeniden hesaplanır; optimistic locking', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 10);

    $this->service->update($wo, ['quantity' => 4, 'assigned_user_id' => $this->operator->id], $wo->lock_version);

    expect($wo->fresh())->quantity->toBe('4.000')->assigned_user_id->toBe($this->operator->id)
        ->and($wo->materials()->where('part_id', $this->f->bearing->id)->value('required_quantity'))->toBe('8.000')
        ->and($wo->materials()->where('part_id', $this->f->varnish->id)->value('planned_scrap'))->toBe('0.040');

    expect(fn () => $this->service->update(WorkOrder::find($wo->id), ['notes' => 'x'], 0))->toThrow(StaleModelException::class);
});

it('tamamlanmış veya iptal edilmiş iş emri düzenlenemez', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 1);
    $this->service->cancel($wo, $this->admin, 'Gerek kalmadı');

    expect(fn () => $this->service->update($wo->fresh(), ['notes' => 'x'], $wo->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'İptal durumundaki iş emri düzenlenemez');
});

it('reçeteyi yenile: güncel reçete kopyalanır, yalnızca planlandıda', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 10);
    app(BomService::class)->update($this->f->model->bomItems()->where('component_part_id', $this->f->bearing->id)->sole(), 3, 0, 0);

    // Açık iş emri eski listeyi korur.
    expect($wo->materials()->where('part_id', $this->f->bearing->id)->value('required_quantity'))->toBe('20.000');

    $this->service->refreshMaterials($wo, $wo->lock_version);
    expect($wo->materials()->where('part_id', $this->f->bearing->id)->value('required_quantity'))->toBe('30.000');

    $this->service->start($wo->fresh(), $this->admin);
    expect(fn () => $this->service->refreshMaterials($wo->fresh(), $wo->fresh()->lock_version))->toThrow(BusinessRuleException::class, 'yalnızca planlandı');
});

it('malzeme durumu eksikleri gösterir (fire dahil)', function () {
    $wo = $this->f->motorWorkOrder($this->admin, 10);
    $this->f->varnish->stockBalance->forceFill(['quantity' => '1.500'])->save();

    $status = collect($this->service->materialStatus($wo))->keyBy(fn ($row) => $row['material']->part_id);

    expect($status[$this->f->varnish->id])->required->toBe('2.100')->available->toBe('1.500')->missing->toBe('0.600')
        ->and($status[$this->f->bearing->id]['missing'])->toBe('20.000');
});

it('parti bağlanabilir', function () {
    $batch = ProductionBatch::factory()->create();

    expect($this->f->motorWorkOrder($this->admin, 1, ['production_batch_id' => $batch->id])->production_batch_id)->toBe($batch->id);
});

describe('sipariş kalemi', function () {
    beforeEach(function () {
        $this->order = Order::factory()->open()->withItem(5, $this->f->model)->create();
        $this->item = $this->order->items()->sole();
    });

    it('kalem kotası aşılamaz; iptal edilen iş emri kotadan düşer', function () {
        $first = $this->f->motorWorkOrder($this->admin, 3, ['order_item_id' => $this->item->id]);

        expect(fn () => $this->f->motorWorkOrder($this->admin, 3, ['order_item_id' => $this->item->id]))
            ->toThrow(BusinessRuleException::class, 'kalan 2 adet');

        $this->f->motorWorkOrder($this->admin, 2, ['order_item_id' => $this->item->id]);

        $this->service->cancel($first, $this->admin, 'Yeniden planlandı');
        $this->f->motorWorkOrder($this->admin, 3, ['order_item_id' => $this->item->id]);

        expect(WorkOrder::where('order_item_id', $this->item->id)->where('status', '!=', 'cancelled')->sum('quantity'))->toBe('5.000');
    });

    it('adet artışı da kotaya tabidir', function () {
        $wo = $this->f->motorWorkOrder($this->admin, 5, ['order_item_id' => $this->item->id]);

        expect(fn () => $this->service->update($wo, ['quantity' => 6], $wo->lock_version))->toThrow(BusinessRuleException::class, 'kotası aşılıyor');
    });

    it('farklı modelin, yarı mamulün veya iptal edilmiş siparişin kalemine bağlanamaz', function () {
        $otherModelOrder = Order::factory()->open()->withItem(5)->create();

        expect(fn () => $this->f->motorWorkOrder($this->admin, 1, ['order_item_id' => $otherModelOrder->items()->sole()->id]))
            ->toThrow(BusinessRuleException::class, 'motor modeli iş emrininkiyle aynı')
            ->and(fn () => $this->f->statorWorkOrder($this->admin, 1, ['order_item_id' => $this->item->id]))
            ->toThrow(BusinessRuleException::class, 'motor modeli iş emrininkiyle aynı');

        $cancelled = Order::factory()->cancelled()->withItem(5, $this->f->model)->create();
        expect(fn () => $this->f->motorWorkOrder($this->admin, 1, ['order_item_id' => $cancelled->items()->sole()->id]))
            ->toThrow(BusinessRuleException::class, 'kalemine iş emri bağlanamaz');
    });

    it('iş emri bağlı kalem silinemez ve miktarı iş emirlerinin altına inemez', function () {
        $this->f->motorWorkOrder($this->admin, 3, ['order_item_id' => $this->item->id]);
        $orders = app(OrderService::class);
        $other = MotorModel::factory()->create();

        expect(fn () => $orders->update($this->order, ['items' => [['motor_model_id' => $other->id, 'quantity' => 1]]], $this->order->lock_version))
            ->toThrow(BusinessRuleException::class, 'kalem silinemez')
            ->and(fn () => $orders->update($this->order->fresh(), ['items' => [['motor_model_id' => $this->f->model->id, 'quantity' => 2]]], $this->order->fresh()->lock_version))
            ->toThrow(BusinessRuleException::class, 'altına inemez');

        $orders->update($this->order->fresh(), ['items' => [['motor_model_id' => $this->f->model->id, 'quantity' => 3]]], $this->order->fresh()->lock_version);
        expect($this->item->fresh()->quantity)->toBe(3);
    });
});

it('görünürlük: operatör yalnızca kendine atananları görür; yönetici ve depo tümünü', function () {
    $mine = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->operator->id]);
    $other = $this->f->motorWorkOrder($this->admin, 1, ['assigned_user_id' => $this->otherOperator->id]);
    $this->f->motorWorkOrder($this->admin, 1);

    expect(WorkOrder::visibleTo($this->operator)->pluck('id')->all())->toBe([$mine->id])
        ->and(WorkOrder::visibleTo($this->warehouse)->count())->toBe(3)
        ->and(WorkOrder::visibleTo($this->admin)->count())->toBe(3)
        ->and($this->operator->can('view', $other))->toBeFalse()
        ->and($this->operator->can('view', $mine))->toBeTrue()
        ->and($this->warehouse->can('view', $other))->toBeTrue()
        ->and($this->warehouse->can('create', WorkOrder::class))->toBeFalse()
        ->and($this->warehouse->can('update', $other))->toBeFalse();
});
