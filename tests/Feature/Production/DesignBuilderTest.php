<?php

use App\Enums\PartType;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DesignBuilder;
use App\Filament\Resources\MotorDesigns\MotorDesignResource;
use App\Filament\Resources\MotorDesigns\Pages\EditMotorDesign;
use App\Filament\Resources\MotorDesigns\Pages\ViewMotorDesign;
use App\Models\BomTemplate;
use App\Models\MotorDesign;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Models\User;
use App\Services\MotorDesignService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Tasarımlar "Motor oluştur" gibi (karar 2026-11-10): motor tipi ve montaj çizimiyle bileşen seçme, kartsız deneme
 * bileşeni, motor görseli, motor modeline çevirme.
 */

beforeEach(function () {
    config(['features.motor_designs' => true]);
    Storage::fake(MotorDesignService::IMAGE_DISK);
    $this->piece = Unit::factory()->whole()->create(['name' => 'adet-t']);
    $this->kg = Unit::factory()->create(['name' => 'kg-t', 'allows_decimal' => true]);
    $this->template = BomTemplate::query()->where('name', 'Trifaze asenkron motor')->sole();
    $this->admin = User::factory()->admin()->create();
    $this->line = fn (string $label): int => $this->template->lines()->where('label', $label)->value('id');
    $this->part = fn (string $type, string $subtype, ?Unit $unit = null, array $data = []): Part => Part::factory()->create([
        'type' => PartType::from($type),
        'subtype_id' => PartSubtype::query()->where('type', $type)->where('name', $subtype)->value('id'),
        'unit_id' => ($unit ?? $this->piece)->id, ...$data,
    ]);
});

it('şablondan tasarım: ürün ve kartsız deneme bileşeni girilir, aynı rulman ön/arka satırda ayrı kalır; stok ve model oluşmaz', function () {
    $body = ($this->part)('semi_finished', 'Gövde', $this->kg);
    $bearing = ($this->part)('component', 'Rulman', data: ['name' => 'Rulman 6202 2RS']);
    $parts = Part::query()->count();

    $design = app(MotorDesignService::class)->build($this->template, ['code' => 'ts-71', 'name' => 'Yeni kapak denemesi', 'power_kw' => '0,55', 'phase' => 'three_phase'], [
        ($this->line)('Gövde') => ['part_id' => $body->id, 'quantity' => '2,4'],
        ($this->line)('Ön rulman') => ['part_id' => $bearing->id, 'quantity' => '1'],
        ($this->line)('Arka rulman') => ['part_id' => $bearing->id, 'quantity' => '1'],
        ($this->line)('Pervane muhafaza tası') => ['description' => 'Plastik fan kapağı (yeni tedarikçi)', 'unit_id' => $this->piece->id, 'quantity' => '1'],
    ]);

    expect($design)->code->toBe('TS-71')->power_kw->toBe('0.550')->bom_template_id->toBe($this->template->id)
        ->and($design->items()->count())->toBe(4)
        ->and($design->items()->where('part_id', $bearing->id)->count())->toBe(2)
        ->and($design->items()->whereNull('part_id')->sole()->templateLine->label)->toBe('Pervane muhafaza tası')
        ->and(Part::query()->count())->toBe($parts)
        ->and(MotorModel::query()->count())->toBe(0);
});

it('bileşensiz, yanlış türde ürün veya birimsiz deneme bileşeniyle tasarım oluşmaz', function () {
    $bearing = ($this->part)('component', 'Rulman');
    $build = fn (array $rows) => app(MotorDesignService::class)->build($this->template, ['code' => 'TS-X', 'name' => 'X'], $rows);

    expect(fn () => $build([]))->toThrow(BusinessRuleException::class, 'En az bir bileşen')
        ->and(fn () => $build([($this->line)('Gövde') => ['part_id' => $bearing->id, 'quantity' => '1']]))->toThrow(BusinessRuleException::class, '01 · Gövde')
        ->and(fn () => $build([($this->line)('Gövde') => ['description' => 'Döküm gövde', 'quantity' => '1']]))->toThrow(BusinessRuleException::class, 'birimi zorunlu')
        ->and(MotorDesign::query()->where('code', 'TS-X')->exists())->toBeFalse();
});

it('ekrandan tasarlanır: çizim ve satırlar görünür, görsel yüklenir, tasarım sayfasında görsel ve çizim çıkar', function () {
    $body = ($this->part)('semi_finished', 'Gövde', $this->kg);
    $bodyLine = ($this->line)('Gövde');
    $fanLine = ($this->line)('Pervane muhafaza tası');
    $this->actingAs($this->admin);

    Livewire::test(DesignBuilder::class)
        ->fillForm(['template_id' => $this->template->id])
        ->assertSee('b3-trifaze.jpg')
        ->assertSee('01 · Gövde')
        ->fillForm(['code' => 'TS-EKRAN', 'name' => 'Ekran tasarımı', 'image_path' => UploadedFile::fake()->image('motor.jpg', 800, 600)])
        ->set("data.rows.{$bodyLine}.part_id", $body->id)
        ->set("data.rows.{$bodyLine}.quantity", '2,4')
        ->assertSee('kg-t')
        ->set("data.rows.{$fanLine}.description", 'Deneme fan kapağı')
        ->set("data.rows.{$fanLine}.unit_id", $this->piece->id)
        ->set("data.rows.{$fanLine}.quantity", '1')
        ->callAction(TestAction::make('save')->schemaComponent('builder-actions', schema: 'content'))
        ->assertHasNoFormErrors()
        ->assertNotified('Tasarım kaydedildi: TS-EKRAN');

    $design = MotorDesign::query()->where('code', 'TS-EKRAN')->sole();
    expect($design->items()->count())->toBe(2)->and($design->image_path)->toStartWith('design-images/');
    Storage::disk(MotorDesignService::IMAGE_DISK)->assertExists($design->image_path);

    $this->get(MotorDesignResource::getUrl('view', ['record' => $design]))->assertOk()
        ->assertSee($design->imageUrl(), false)->assertSee('Montaj çizimi')->assertSee('Trifaze asenkron motor');
    $this->get($design->imageUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // Görsel değişince veya kaldırılınca eski dosya silinir.
    $old = $design->image_path;
    Storage::disk(MotorDesignService::IMAGE_DISK)->put('design-images/yeni.png', 'y');
    $design = app(MotorDesignService::class)->update($design, ['image_path' => 'design-images/yeni.png'], $design->lock_version);
    Storage::disk(MotorDesignService::IMAGE_DISK)->assertMissing($old);
    $design = app(MotorDesignService::class)->update($design, ['image_path' => null], $design->lock_version);
    Storage::disk(MotorDesignService::IMAGE_DISK)->assertMissing('design-images/yeni.png');
    $this->get(MotorDesignResource::getUrl('edit', ['record' => $design]))->assertOk()->assertSee('Motor görseli');
    Livewire::test(EditMotorDesign::class, ['record' => $design->getRouteKey()])
        ->set('data.image_path', [])   // tarayıcıda tek dosyalı alan eskisini kaldırır
        ->fillForm(['image_path' => UploadedFile::fake()->image('ekran.png', 400, 300)])->call('save')->assertHasNoFormErrors();
    expect($design->fresh()->image_path)->toStartWith('design-images/')->toEndWith('.png');

    expect(fn () => app(MotorDesignService::class)->update($design->fresh(), ['image_path' => 'part-images/x.jpg'], $design->fresh()->lock_version))
        ->toThrow(BusinessRuleException::class, 'Geçersiz görsel');
});

it('ekran ve görsel yetkisi: muhasebe görür ama tasarlayamaz, operatör ve depo göremez', function () {
    $design = app(MotorDesignService::class)->create(['code' => 'TS-Y', 'name' => 'Y']);
    Storage::disk(MotorDesignService::IMAGE_DISK)->put('design-images/y.jpg', UploadedFile::fake()->image('y.jpg')->getContent());
    $design->forceFill(['image_path' => 'design-images/y.jpg'])->save();

    $this->actingAs(User::factory()->withRoles(Role::Accounting)->create());
    $this->get(DesignBuilder::getUrl())->assertForbidden();
    $this->get($design->imageUrl())->assertOk();

    foreach (['operator', 'warehouse'] as $role) {
        $this->actingAs(User::factory()->{$role}()->create());
        $this->get($design->imageUrl())->assertForbidden();
    }

    config(['features.motor_designs' => false]);
    $this->actingAs($this->admin)->get($design->imageUrl())->assertForbidden();
    $this->get(DesignBuilder::getUrl())->assertForbidden();
});

it('motor modeline çevrilir: reçete şablon satırlarından toplanır, elle eklenen ürün girer, deneme bileşeni girmez; bir kez', function () {
    $body = ($this->part)('semi_finished', 'Gövde', $this->kg);
    $bearing = ($this->part)('component', 'Rulman');
    $extra = ($this->part)('direct_consumable', 'Bağlantı elemanı', data: ['name' => 'Ek civata']);
    $service = app(MotorDesignService::class);
    $design = $service->build($this->template, ['code' => 'TS-M', 'name' => 'Model olacak', 'power_kw' => '0,75', 'speed_rpm' => 1400], [
        ($this->line)('Gövde') => ['part_id' => $body->id, 'quantity' => '2,4'],
        ($this->line)('Ön rulman') => ['part_id' => $bearing->id, 'quantity' => '1'],
        ($this->line)('Arka rulman') => ['part_id' => $bearing->id, 'quantity' => '1'],
        ($this->line)('Pervane muhafaza tası') => ['description' => 'Deneme fan kapağı', 'unit_id' => $this->piece->id, 'quantity' => '1'],
    ]);
    $service->addItem($design, ['part_id' => $extra->id, 'quantity' => '4']);

    $this->actingAs($this->admin);
    Livewire::test(ViewMotorDesign::class, ['record' => $design->getRouteKey()])
        ->callAction('convert', ['code' => 'M75-YENI', 'name' => 'Yeni 0,75 kW'])
        ->assertNotified('Motor modeli oluşturuldu: M75-YENI');

    $model = MotorModel::query()->where('code', 'M75-YENI')->sole();
    $bom = $model->finishedPart->bomItems()->get()->keyBy('component_part_id');
    expect($model)->power_kw->toBe('0.750')->speed_rpm->toBe(1400)
        ->and($bom)->toHaveCount(3)
        ->and($bom[$bearing->id]->quantity_per_unit)->toBe('2.000')
        ->and($bom[$extra->id]->quantity_per_unit)->toBe('4.000')
        ->and($design->fresh()->motor_model_id)->toBe($model->id)
        ->and(fn () => $service->convertToModel($design->fresh(), ['code' => 'M75-2']))->toThrow(BusinessRuleException::class, 'zaten');

    // Yetkisi olmayan (muhasebe) düğmeyi görmez; kartlı bileşeni olmayan tasarım çevrilmez.
    $draft = $service->create(['code' => 'TS-BOS', 'name' => 'Boş']);
    $service->addItem($draft, ['description' => 'Deneme', 'unit_id' => $this->piece->id, 'quantity' => '1']);
    expect(fn () => $service->convertToModel($draft))->toThrow(BusinessRuleException::class, 'stok kartı olan bileşen yok');
    $this->actingAs(User::factory()->withRoles(Role::Accounting)->create());
    Livewire::test(ViewMotorDesign::class, ['record' => $draft->getRouteKey()])->assertActionHidden('convert');
});
