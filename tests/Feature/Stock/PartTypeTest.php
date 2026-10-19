<?php

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->unit = Unit::factory()->create();
});

it('her türün muhasebe kodu CLAUDE.md bölüm 4 ile aynıdır', function () {
    expect(collect(PartType::cases())->mapWithKeys(fn (PartType $t) => [$t->value => $t->accountingCode()])->all())
        ->toBe([
            'raw_material' => '150.01',
            'component' => '150.02',
            'direct_consumable' => '150.03',
            'indirect_consumable' => '157',
            'semi_finished' => '151',
            'finished_good' => '152',
        ])
        ->and(PartType::IndirectConsumable->getLabel())->toBe('157 – Endirekt sarf');
});

it('veritabanı yalnızca 6 türü kabul eder; eski "consumable" değeri reddedilir', function () {
    $insert = fn (string $type) => DB::transaction(fn () => DB::table('parts')->insert([
        'code' => 'T-'.$type, 'name' => 'x', 'unit_id' => $this->unit->id, 'type' => $type,
        'critical_level' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]));

    foreach (PartType::cases() as $type) {
        $insert($type->value);
    }

    expect(fn () => $insert('consumable'))->toThrow(QueryException::class);
});

it('migration mevcut sarf kartlarını direkt sarfa geçirir', function () {
    $migration = require database_path('migrations/2026_10_16_100000_expand_part_types.php');

    $migration->down();
    $id = DB::table('parts')->insertGetId([
        'code' => 'ESKI-SARF', 'name' => 'Vernik', 'unit_id' => $this->unit->id, 'type' => 'consumable',
        'critical_level' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration->up();

    expect(DB::table('parts')->where('id', $id)->value('type'))->toBe('direct_consumable');
});

it('mamul kartı elle açılamaz', function () {
    expect(fn () => app(PartService::class)->create([
        'code' => 'M-1', 'name' => 'Motor', 'unit_id' => $this->unit->id, 'type' => PartType::FinishedGood,
    ]))->toThrow(BusinessRuleException::class, 'Mamul kartı elle açılamaz');

    expect(Part::count())->toBe(0);
});

it('kart türü değiştirilebilir ve denetim izine yazılır; mamul türüne alınamaz', function () {
    $this->actingAs($this->user);
    $service = app(PartService::class);
    $part = Part::factory()->withStock(5)->create(['type' => PartType::DirectConsumable]);

    // Hareketi olsa da yanlış sınıflanan kart düzeltilebilir.
    $service->update($part, ['type' => PartType::IndirectConsumable->value], $part->lock_version);

    expect($part->fresh()->type)->toBe(PartType::IndirectConsumable);

    $activity = Activity::where('subject_type', 'part')->where('subject_id', $part->id)->where('event', 'updated')->latest('id')->first();
    expect($activity->attribute_changes['old']['type'])->toBe('direct_consumable')
        ->and($activity->attribute_changes['attributes']['type'])->toBe('indirect_consumable');

    expect(fn () => $service->update($part->fresh(), ['type' => PartType::FinishedGood->value], $part->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'mamul türüne alınamaz');

    expect($part->fresh()->type)->toBe(PartType::IndirectConsumable);
});

it('mamul kartının türü değiştirilemez', function () {
    // Mamul kartları Aşama 4'te motor modelinden oluşacak; burada doğrudan oluşturulur.
    $part = Part::factory()->create(['type' => PartType::FinishedGood]);

    expect(fn () => app(PartService::class)->update($part, ['type' => PartType::Component->value], $part->lock_version))
        ->toThrow(BusinessRuleException::class, 'mamul kartının türü değiştirilemez');
});

it('formda türler muhasebe koduyla listelenir ve mamul seçilemez', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(CreatePart::class);
    $options = $component->instance()->form->getComponent('type')->getOptions();

    expect($options)->toBe(PartType::manualOptions())
        ->toHaveKey('indirect_consumable', '157 – Endirekt sarf')
        ->not->toHaveKey('finished_good');

    $component->fillForm([
        'code' => 'OKS-1', 'name' => 'Oksijen tüpü', 'type' => PartType::IndirectConsumable->value,
        'unit_id' => $this->unit->id, 'critical_level' => '0',
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Part::where('code', 'OKS-1')->sole()->type)->toBe(PartType::IndirectConsumable);

    $component = Livewire::test(CreatePart::class)
        ->fillForm(['code' => 'M-1', 'name' => 'Motor', 'type' => PartType::FinishedGood->value, 'unit_id' => $this->unit->id, 'critical_level' => '0'])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Part::where('code', 'M-1')->exists())->toBeFalse();
});

it('mamul kartının formunda tür kilitlidir', function () {
    $this->actingAs(User::factory()->admin()->create());
    $part = Part::factory()->create(['type' => PartType::FinishedGood]);

    Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
        ->assertFormFieldDisabled('type');
});
