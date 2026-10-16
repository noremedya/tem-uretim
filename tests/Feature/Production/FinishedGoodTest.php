<?php

use App\Enums\PartType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\StockOperation;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\MotorModelService;
use App\Services\PartService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(MotorModelService::class);
});

function createModel(array $data = []): MotorModel
{
    return app(MotorModelService::class)->create(['code' => 'TM-71', 'name' => 'Trifaze 0,37 kW', ...$data]);
}

it('motor modeli oluşturulunca mamul kartı açılır', function () {
    $model = createModel();
    $part = $model->finishedPart;

    expect($part)
        ->code->toBe('TM-71')
        ->name->toBe('Trifaze 0,37 kW')
        ->type->toBe(PartType::FinishedGood)
        ->is_active->toBeTrue()
        ->and($part->unit->name)->toBe('Adet')
        ->and($part->unit->allows_decimal)->toBeFalse()
        ->and((string) $part->stockBalance->quantity)->toBe('0.000');
});

it('mevcut "adet" birimini kullanır, yoksa açar', function () {
    $existing = Unit::factory()->create(['name' => 'ADET', 'allows_decimal' => false]);

    expect(createModel()->finishedPart->unit_id)->toBe($existing->id)
        ->and(Unit::count())->toBe(1);
});

it('aynı kodlu stok kartı varsa model oluşturulmaz', function () {
    Part::factory()->create(['code' => 'TM-71']);

    expect(fn () => createModel())->toThrow(BusinessRuleException::class, 'TM-71 koduyla bir stok kartı zaten var');

    expect(MotorModel::count())->toBe(0)
        ->and(Part::count())->toBe(1);
});

it('model kodu, adı ve aktifliği mamul kartına yansır', function () {
    $model = createModel();

    $this->service->update($model, ['code' => 'TM-71B', 'name' => 'Yeni ad'], $model->lock_version);
    expect($model->finishedPart->fresh())->code->toBe('TM-71B')->name->toBe('Yeni ad');

    $this->service->deactivate($model->fresh());
    expect($model->finishedPart->fresh()->is_active)->toBeFalse();

    $this->service->activate($model->fresh());
    expect($model->finishedPart->fresh()->is_active)->toBeTrue();
});

it('model kodu başka bir stok kartının koduna değiştirilemez', function () {
    $model = createModel();
    Part::factory()->create(['code' => 'DOLU']);

    expect(fn () => $this->service->update($model, ['code' => 'DOLU'], $model->lock_version))
        ->toThrow(BusinessRuleException::class, 'DOLU koduyla');

    expect($model->fresh()->code)->toBe('TM-71');
});

it('mamul kartının kodu, adı ve birimi karttan değiştirilemez; barkod ve kritik seviye değişir', function () {
    $part = createModel()->finishedPart;
    $parts = app(PartService::class);

    expect(fn () => $parts->update($part, ['code' => 'BASKA'], $part->lock_version))
        ->toThrow(BusinessRuleException::class, 'motor modelinden yönetilir');

    $parts->update($part->fresh(), ['barcode' => '8690001', 'critical_level' => '2'], $part->fresh()->lock_version);

    expect($part->fresh())->barcode->toBe('8690001')->critical_level->toBe('2.000')->code->toBe('TM-71');
});

it('mamul kartı karttan pasifleştirilemez', function () {
    $part = createModel()->finishedPart;

    expect(fn () => app(PartService::class)->deactivate($part))->toThrow(BusinessRuleException::class, 'motor modeliyle birlikte')
        ->and($this->admin->can('deactivate', $part))->toBeFalse();
});

it('mamul kartında elle stok hareketi yapılamaz (servis)', function (string $method, array $args) {
    $part = createModel()->finishedPart;

    expect(fn () => app(StockService::class)->{$method}($part, ...[...$args, $this->admin, 'Deneme']))
        ->toThrow(BusinessRuleException::class, 'Mamul stoğu elle değiştirilemez');

    expect(StockMovement::count())->toBe(0);
})->with([
    'giriş' => ['stockIn', ['1']],
    'çıkış' => ['stockOut', ['1']],
    'iade' => ['returnToStock', ['1']],
    'sayım' => ['adjustCount', ['3']],
]);

function insertMovement(Part $part, string $type, string $quantity, string $before = '0'): void
{
    DB::transaction(fn () => DB::table('stock_movements')->insert([
        'part_id' => $part->id, 'type' => $type, 'quantity' => $quantity,
        'balance_before' => $before, 'balance_after' => bcadd($before, $quantity, 3),
        'description' => 'Doğrudan SQL', 'user_id' => test()->admin->id, 'created_at' => now(),
    ]));
}

it('mamul kartında elle stok hareketi yapılamaz (veritabanı trigger\'ı)', function () {
    $part = createModel()->finishedPart;

    expect(fn () => insertMovement($part, 'stock_in', '1'))->toThrow(QueryException::class, 'Mamul kartında elle stok hareketi yapılamaz');

    // Üretimden giriş serbest.
    insertMovement($part, 'production_output', '1');
    expect(StockMovement::count())->toBe(1);
});

it('yeni hareket tiplerinin yönü veritabanında zorlanır', function (string $type, string $quantity) {
    $part = Part::factory()->create(['type' => PartType::SemiFinished]);

    expect(fn () => insertMovement($part, $type, $quantity, '5'))->toThrow(QueryException::class);
})->with([
    'üretimden giriş eksi olamaz' => ['production_output', '-1'],
    'fire artı olamaz' => ['production_scrap', '1'],
    'sevk artı olamaz' => ['shipment', '1'],
]);

it('üretim ve sevk hareketleri elle ters kaydedilemez', function (StockMovementType $type) {
    expect($type->isProductionFlow())->toBeTrue();

    $part = Part::factory()->create(['type' => PartType::SemiFinished]);
    insertMovement($part, $type->value, $type->sign() > 0 ? '1' : '-1', '5');

    expect(fn () => app(StockService::class)->correct(StockMovement::sole(), $this->admin, 'Düzeltme'))
        ->toThrow(BusinessRuleException::class, "{$type->label()} hareketleri elle ters kaydedilemez");
})->with([StockMovementType::ProductionOutput, StockMovementType::ProductionScrap, StockMovementType::Shipment]);

it('motor modeli mamul olmayan bir karta bağlanamaz (veritabanı)', function () {
    $model = createModel();
    $raw = Part::factory()->create(['type' => PartType::RawMaterial]);

    expect(fn () => DB::transaction(fn () => DB::table('motor_models')->where('id', $model->id)->update(['finished_part_id' => $raw->id])))
        ->toThrow(QueryException::class, 'mamul türünde olmalıdır');
});

it('stok işlemi sayfasında mamul kartı seçilemez', function () {
    $this->actingAs($this->admin);
    $part = createModel()->finishedPart;
    Part::factory()->create(['code' => 'TM-71-SAC', 'type' => PartType::RawMaterial]);

    $results = Livewire\Livewire::test(StockOperation::class)->instance()
        ->form->getComponent('part_id')->getSearchResults('TM-71');

    expect($results)->not->toHaveKey($part->id)->toHaveCount(1);
});

it('migration mevcut motor modelleri için mamul kartı açar', function () {
    $migration = require database_path('migrations/2026_10_17_100000_add_finished_goods_and_bom.php');
    $migration->down();

    $id = DB::table('motor_models')->insertGetId([
        'code' => 'ESKI-1', 'name' => 'Eski model', 'is_active' => true, 'lock_version' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration->up();

    $model = MotorModel::findOrFail($id);
    expect($model->finishedPart)
        ->code->toBe('ESKI-1')
        ->type->toBe(PartType::FinishedGood)
        ->and($model->finishedPart->stockBalance)->not->toBeNull();
});
