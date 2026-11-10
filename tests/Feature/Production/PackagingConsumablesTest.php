<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomTemplate;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Services\BomService;
use App\Services\MotorBuilderService;

/*
 * Sarf malzemeleri (karar 2026-11-10): boya, koli, köpük, balonlu poşet motor reçetesinden motor başına düşer;
 * şerit bant ve koli bandı endirekt sarftır (reçeteye girmez, depodan toplu gider çıkışı).
 */

it('motor şablonlarında ambalaj ve boya satırları var; seçilen ürünler motor reçetesine girer', function () {
    foreach (['Trifaze asenkron motor', 'Monofaze MD motor', 'Monofaze MSD motor', 'Vibrasyon motoru'] as $name) {
        $labels = BomTemplate::query()->where('name', $name)->sole()->lines()->pluck('label');
        expect($labels)->toContain('Boya', 'Koli', 'Köpük', 'Balonlu poşet');
    }

    $template = BomTemplate::query()->where('name', 'Trifaze asenkron motor')->sole();
    $make = fn (string $subtype) => Part::factory()->create(['type' => PartType::DirectConsumable,
        'subtype_id' => PartSubtype::query()->where('type', 'direct_consumable')->where('name', $subtype)->value('id')]);
    $box = $make('Koli');
    $foam = $make('Köpük');
    $line = fn (string $label) => $template->lines()->where('label', $label)->value('id');

    $result = app(MotorBuilderService::class)->build($template, ['code' => 'M-AMB', 'name' => 'Ambalajlı'], [
        $line('Koli') => ['part_id' => $box->id, 'quantity_per_unit' => '1'],
        $line('Köpük') => ['part_id' => $foam->id, 'quantity_per_unit' => '2'],
    ]);

    expect($result['model']->finishedPart->bomItems()->pluck('component_part_id')->sort()->values()->all())->toBe(collect([$box->id, $foam->id])->sort()->values()->all());
});

it('şerit bant ve koli bandı endirekt sarf alt türündedir ve reçeteye girmez', function () {
    $tape = Part::factory()->create(['type' => PartType::IndirectConsumable,
        'subtype_id' => PartSubtype::query()->where('type', 'indirect_consumable')->where('name', 'Bant')->value('id')]);
    $parent = Part::factory()->create(['type' => PartType::SemiFinished]);

    expect($tape->subtype_id)->not->toBeNull()
        ->and(fn () => app(BomService::class)->add($parent, $tape, 1))->toThrow(BusinessRuleException::class, 'reçeteye bileşen olarak eklenemez');
});
