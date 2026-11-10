<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\StaleModelException;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Resources\WorkOrders\RelationManagers\MaterialsRelationManager;
use App\Models\Part;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\WorkOrderMaterialService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ProductionFixtures;

/*
 * İş emrinde malzeme değişikliği (karar 2026-11-10): her değişiklikte "yalnızca bu iş emri mi, reçeteye de mi" sorulur.
 * Fikstür reçetesi (motor başına): 1 stator, 2 rulman, 0,2 kg vernik (%5 fire).
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->f = ProductionFixtures::create();
    $this->wo = $this->f->motorWorkOrder($this->admin, 10);
    $this->service = app(WorkOrderMaterialService::class);
    $this->bom = fn () => $this->f->model->finishedPart->bomItems()->get()->keyBy('component_part_id');
});

it('yalnızca bu iş emri: miktar değişir, ihtiyaç yeniden hesaplanır, reçete değişmez; denetim izine yazılır', function () {
    $bearing = $this->wo->materials()->where('part_id', $this->f->bearing->id)->sole();
    $this->service->save($this->wo, $bearing, ['quantity_per_unit' => '3'], false, $this->admin, $this->wo->lock_version);

    expect($bearing->fresh()->required_quantity)->toBe('30.000')
        ->and(($this->bom)()[$this->f->bearing->id]->quantity_per_unit)->toBe('2.000')
        ->and(Activity::query()->where('subject_type', 'work_order')->latest('id')->value('description'))->toContain('yalnızca bu iş emri');
});

it('kalıcı: reçeteye de işlenir; eklenen ve çıkarılan malzeme reçetede de eklenir/çıkar, sonraki iş emri böyle açılır', function () {
    $tape = Part::factory()->create(['code' => 'SARF-BANT', 'type' => PartType::DirectConsumable, 'unit_id' => $this->f->piece->id]);
    $wo = $this->wo;

    $this->service->save($wo, null, ['part_id' => $tape->id, 'quantity_per_unit' => '1'], true, $this->admin, $wo->lock_version);
    $varnish = $wo->materials()->where('part_id', $this->f->varnish->id)->sole();
    $this->service->save($wo, $varnish, ['quantity_per_unit' => '0,25', 'scrap_rate' => '5'], true, $this->admin, $wo->lock_version);
    $this->service->remove($wo, $wo->materials()->where('part_id', $this->f->bearing->id)->sole(), true, $this->admin, $wo->lock_version);

    $bom = ($this->bom)();
    expect($bom->has($tape->id))->toBeTrue()->and($bom->has($this->f->bearing->id))->toBeFalse()
        ->and($bom[$this->f->varnish->id]->quantity_per_unit)->toBe('0.250')
        ->and($wo->materials()->pluck('part_id')->sort()->values()->all())->toBe(collect([$this->f->stator->id, $this->f->varnish->id, $tape->id])->sort()->values()->all());

    $next = $this->f->motorWorkOrder($this->admin, 5);
    expect($next->materials()->where('part_id', $tape->id)->value('required_quantity'))->toBe('5.000');
});

it('kurallar: üretim kaydı varken değişmez, eski sürümle reddedilir, mamul eklenemez, reçete yetkisi yoksa kalıcı yapılamaz', function () {
    $bearing = $this->wo->materials()->where('part_id', $this->f->bearing->id)->sole();

    expect(fn () => $this->service->save($this->wo, $bearing, ['quantity_per_unit' => '3'], false, $this->admin, $this->wo->lock_version - 1))->toThrow(StaleModelException::class)
        ->and(fn () => $this->service->save($this->wo, null, ['part_id' => $this->f->model->finished_part_id, 'quantity_per_unit' => '1'], false, $this->admin, $this->wo->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class)
        ->and(fn () => $this->service->save($this->wo, null, ['part_id' => $this->f->bearing->id, 'quantity_per_unit' => '1'], false, $this->admin, $this->wo->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'zaten var');

    $operator = User::factory()->operator()->create();
    expect(fn () => $this->service->save($this->wo, $bearing, ['quantity_per_unit' => '3'], false, $operator, $this->wo->fresh()->lock_version))->toThrow(BusinessRuleException::class, 'yetkiniz yok');

    $started = $this->f->stockUp($this->admin)->startedMotorWorkOrder($this->admin, 4);
    app(ProductionService::class)->recordProduction($started, $this->admin, '1');
    expect(fn () => $this->service->save($started, $started->materials()->first(), ['quantity_per_unit' => '3'], false, $this->admin, $started->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'Üretim kaydı olan');
});

it('ekranda değişiklik penceresi kapsamı sorar; seçmeden kaydedilmez', function () {
    $this->actingAs($this->admin);
    $bearing = $this->wo->materials()->where('part_id', $this->f->bearing->id)->sole();
    $rm = fn () => Livewire::test(MaterialsRelationManager::class, ['ownerRecord' => $this->wo->fresh(), 'pageClass' => ViewWorkOrder::class]);

    $rm()->callAction(TestAction::make('editMaterial')->table($bearing), ['quantity_per_unit' => '4'])
        ->assertHasActionErrors(['scope' => 'required']);
    $rm()->callAction(TestAction::make('editMaterial')->table($bearing), ['quantity_per_unit' => '4', 'scope' => 'work_order'])
        ->assertHasNoActionErrors()->assertNotified('Malzeme değiştirildi');

    expect($bearing->fresh()->quantity_per_unit)->toBe('4.000')->and(($this->bom)()[$this->f->bearing->id]->quantity_per_unit)->toBe('2.000');
});
