<?php

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\PartResource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\User;
use App\Services\BrandService;
use App\Services\PartService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Aşama A: "Ürünler" adı, ürüne marka (Markalar tanım listesi) ve açıklama, menüde arama.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('marka oluşturulur; ad büyük/küçük harf ve Türkçe I/ı bakımından tekildir (servis ve veritabanı)', function () {
    $service = app(BrandService::class);
    $service->create(['name' => ' Işık Rulman ']);

    expect(Brand::sole()->name)->toBe('Işık Rulman');
    expect(fn () => $service->create(['name' => 'ışık rulman']))->toThrow(BusinessRuleException::class, 'zaten var');
    expect(fn () => DB::transaction(fn () => DB::table('brands')->insert(['name' => 'IŞIK RULMAN ', 'is_active' => true, 'lock_version' => 0])))
        ->toThrow(QueryException::class, 'brands_name_normalized_unique');
    expect(fn () => $service->create(['name' => '  ']))->toThrow(BusinessRuleException::class, 'zorunlu');
});

it('ürüne marka ve açıklama girilir; pasif marka yeni seçilemez ama mevcut üründe kalır', function () {
    $service = app(BrandService::class);
    $brand = $service->create(['name' => 'SKF']);
    $part = Part::factory()->create();
    $parts = app(PartService::class);

    $part = $parts->update($part, ['brand_id' => $brand->id, 'description' => '  Ön rulman, 2RS  '], $part->lock_version);
    expect($part->fresh())->brand_id->toBe($brand->id)->description->toBe('Ön rulman, 2RS');

    $service->deactivate($brand);
    // Mevcut markası korunur; başka alan değişebilir.
    $part = $parts->update($part->fresh(), ['name' => 'Yeni ad'], $part->fresh()->lock_version);
    expect($part->brand_id)->toBe($brand->id);

    $other = Part::factory()->create();
    expect(fn () => $parts->update($other, ['brand_id' => $brand->id], $other->lock_version))->toThrow(BusinessRuleException::class, 'pasif');
});

it('ürün formunda marka seçilir ve sayfada görünür; menüde "Ürünler" ve "Markalar"', function () {
    $this->actingAs($this->admin);
    $brand = app(BrandService::class)->create(['name' => 'ZVL']);
    $part = Part::factory()->create();

    Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
        ->fillForm(['brand_id' => $brand->id, 'description' => 'Test açıklaması'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->get(PartResource::getUrl('view', ['record' => $part]))->assertOk()->assertSee('ZVL')->assertSee('Test açıklaması');
    expect(PartResource::getNavigationLabel())->toBe('Ürünler')
        ->and(BrandResource::getNavigationLabel())->toBe('Markalar');
});

it('markayı yalnızca yönetici yönetir; operatör ve depo listeyi görür', function () {
    $brand = Brand::factory()->create();

    $this->actingAs($this->admin);
    Livewire::test(CreateBrand::class)->fillForm(['name' => 'FAG'])->call('create')->assertHasNoFormErrors();

    foreach ([User::factory()->operator()->create(), User::factory()->warehouse()->create()] as $user) {
        $this->actingAs($user);
        $this->get(BrandResource::getUrl('index'))->assertOk();
        $this->get(BrandResource::getUrl('create'))->assertForbidden();
        $this->get(BrandResource::getUrl('edit', ['record' => $brand]))->assertForbidden();
    }
});

it('menüde arama kutusu vardır', function () {
    $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Menüde ara', false)->assertSee('navSearch()', false);
});
