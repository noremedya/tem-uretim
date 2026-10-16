<?php

namespace Tests\Support;

use App\Enums\PartType;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BomService;
use App\Services\StockService;
use App\Services\WorkOrderService;

/**
 * Üretim testleri için kurulum: reçeteli motor modeli ve yarı mamul.
 *
 * Motor (mamul) reçetesi: 1 stator (yarı mamul, adet) + 2 rulman (komponent, adet, %0 fire) + 0,2 kg vernik (%5 fire).
 * Stator reçetesi: 1,25 kg silisli sac (%4 fire) + 0,8 kg bakır.
 */
final class ProductionFixtures
{
    public MotorModel $model;

    public Part $stator;

    public Part $bearing;

    public Part $varnish;

    public Part $sheet;

    public Part $copper;

    public Unit $kg;

    public Unit $piece;

    public static function create(): self
    {
        $f = new self;
        $f->kg = Unit::factory()->create(['name' => 'kg-'.uniqid(), 'allows_decimal' => true]);
        $f->piece = Unit::factory()->whole()->create(['name' => 'adet-'.uniqid()]);

        $f->model = MotorModel::factory()->create(['code' => 'TM-71', 'name' => 'Trifaze 0,37 kW']);
        $f->stator = Part::factory()->create(['code' => 'YM-STATOR-71', 'type' => PartType::SemiFinished, 'unit_id' => $f->piece->id]);
        $f->bearing = Part::factory()->create(['code' => 'RUL-6202', 'type' => PartType::Component, 'unit_id' => $f->piece->id]);
        $f->varnish = Part::factory()->create(['code' => 'SARF-VERNIK', 'type' => PartType::DirectConsumable, 'unit_id' => $f->kg->id]);
        $f->sheet = Part::factory()->create(['code' => 'HM-SAC', 'type' => PartType::RawMaterial, 'unit_id' => $f->kg->id]);
        $f->copper = Part::factory()->create(['code' => 'HM-BAKIR', 'type' => PartType::RawMaterial, 'unit_id' => $f->kg->id]);

        $boms = app(BomService::class);
        $boms->add($f->model->finishedPart, $f->stator, 1);
        $boms->add($f->model->finishedPart, $f->bearing, 2);
        $boms->add($f->model->finishedPart, $f->varnish, '0,2', 5);
        $boms->add($f->stator, $f->sheet, '1,25', 4);
        $boms->add($f->stator, $f->copper, '0,8');

        return $f;
    }

    /** Malzemelere bol stok girer (StockService üzerinden). */
    public function stockUp(User $user, string $stators = '100'): self
    {
        $stock = app(StockService::class);

        foreach ([[$this->bearing, '1000'], [$this->varnish, '100'], [$this->sheet, '1000'], [$this->copper, '1000']] as [$part, $quantity]) {
            $stock->stockIn($part, $quantity, $user, 'Test stoğu');
        }

        if ($stators !== '0') {
            $stock->stockIn($this->stator, $stators, $user, 'Test stoğu');
        }

        return $this;
    }

    /** Başlatılmış (üretimde) mamul iş emri. */
    public function startedMotorWorkOrder(User $actor, string|int $quantity = 10, array $data = []): WorkOrder
    {
        return app(WorkOrderService::class)->start($this->motorWorkOrder($actor, $quantity, $data), $actor);
    }

    /** Mamul (motor) iş emri. */
    public function motorWorkOrder(User $actor, string|int $quantity = 10, array $data = []): WorkOrder
    {
        return app(WorkOrderService::class)->create([
            'product_part_id' => $this->model->finished_part_id,
            'quantity' => $quantity,
            'planned_end' => today()->addWeek(),
            ...$data,
        ], $actor);
    }

    /** Yarı mamul (stator) iş emri. */
    public function statorWorkOrder(User $actor, string|int $quantity = 10, array $data = []): WorkOrder
    {
        return app(WorkOrderService::class)->create([
            'product_part_id' => $this->stator->id,
            'quantity' => $quantity,
            'planned_end' => today()->addWeek(),
            ...$data,
        ], $actor);
    }
}
