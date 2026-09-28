<?php

use App\Enums\StockMovementType;
use App\Filament\Pages\StockOperation;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->warehouse()->create();
    $this->actingAs($this->user);
    $this->part = Part::factory()->for(Unit::factory()->state(['name' => 'kg']))->withStock(10, $this->user)->create();
});

function movementsOf(Part $part): int
{
    return StockMovement::where('part_id', $part->id)->count();
}

it('giriş yapar ve yeni bakiyeyi gösterir', function () {
    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '2,5'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Giriş kaydedildi')
        ->assertSet('lastResult.body', "{$this->part->code} {$this->part->name}: +2,5 kg. Yeni bakiye: 12,5 kg")
        ->assertSee('Yeni bakiye: 12,5 kg')
        // Tür ve parça korunur, miktar temizlenir.
        ->assertSchemaStateSet(['type' => 'stock_in', 'part_id' => $this->part->id, 'quantity' => null]);

    expect((string) $this->part->stockBalance()->value('quantity'))->toBe('12.500');
});

it('çıkışta açıklama zorunludur; yetersiz stokta anlaşılır hata verir', function () {
    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockOut->value, 'part_id' => $this->part->id, 'quantity' => '3'])
        ->call('save')
        ->assertHasFormErrors(['description' => 'required'])
        ->fillForm(['description' => 'Tedarikçiye iade', 'quantity' => '11'])
        ->call('save')
        ->assertNotified('İşlem yapılamadı');

    expect(movementsOf($this->part))->toBe(1);
});

it('geçersiz miktar reddedilir', function () {
    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '1,2345'])
        ->call('save')
        ->assertHasFormErrors(['quantity']);
});

it('sayımda fark yoksa hareket oluşmaz ve bilgi verilir', function () {
    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::CountAdjustment->value, 'part_id' => $this->part->id, 'quantity' => '10', 'description' => 'Sayım'])
        ->call('save')
        ->assertNotified('Bakiye zaten doğru, hareket oluşturulmadı');

    expect(movementsOf($this->part))->toBe(1);
});

it('başarılı işlemden sonra form sıfırlanır ve yeni gönderim anahtarı üretilir', function () {
    $page = Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '5', 'description' => 'İrsaliye 1']);
    $firstKey = $page->get('submissionKey');

    $page->call('save')
        ->assertNotified('Giriş kaydedildi')
        ->assertSchemaStateSet(['type' => 'stock_in', 'part_id' => $this->part->id, 'quantity' => null, 'description' => null]);

    expect($page->get('submissionKey'))->not->toBe($firstKey)
        ->and(StockMovement::where('idempotency_key', $firstKey)->count())->toBe(1);
});

it('aynı parça ve miktarla formu yeniden doldurarak yapılan iki bilinçli işlem iki hareket oluşturur', function () {
    // Ör. iki ayrı teslimat: depocu aynı parçayı ve miktarı iki kez girer.
    $page = Livewire::test(StockOperation::class);

    foreach ([1, 2] as $delivery) {
        $page->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '5'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Giriş kaydedildi');
    }

    $page->assertSee('Yeni bakiye: 20 kg');

    $inbound = StockMovement::where('part_id', $this->part->id)->where('type', StockMovementType::StockIn)->where('quantity', 5)->get();
    expect($inbound)->toHaveCount(2)
        ->and($inbound->pluck('idempotency_key')->unique())->toHaveCount(2)
        ->and((string) $this->part->stockBalance()->value('quantity'))->toBe('20.000');
});

it('ilk yanıttan sonra kuyruktan gelen değişmemiş istek hareket oluşturmaz', function () {
    // Yeni anahtarı ve temizlenmiş miktarı taşır: doğrulamada durur, stok değişmez.
    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '5'])
        ->call('save')
        ->call('save')
        ->assertHasFormErrors(['quantity' => 'required']);

    expect(movementsOf($this->part))->toBe(2)
        ->and((string) $this->part->stockBalance()->value('quantity'))->toBe('15.000');
});

it('aynı anahtarla gelen iki istek tek hareket oluşturur; ikincisi bildirimi tekrarlamaz', function () {
    // Çift tıklamada iki istek aynı anlık görüntüden (aynı anahtar) çıkar. İlki işlenip commit edildikten
    // sonra ikincisi sunucuya ulaşır.
    $page = Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $this->part->id, 'quantity' => '5']);

    app(StockService::class)->stockIn($this->part, 5, $this->user, null, $page->get('submissionKey')); // ilk istek

    $page->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified()
        // İlk işlemin sonucu gösterilir.
        ->assertSet('lastResult.title', 'Giriş kaydedildi')
        ->assertSee('Yeni bakiye: 15 kg');

    expect(movementsOf($this->part))->toBe(2)
        ->and((string) $this->part->stockBalance()->value('quantity'))->toBe('15.000');
});

it('kaydet butonu istek sürerken pasifleşir', function () {
    Livewire::test(StockOperation::class)
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('type="submit"');
});

it('gönderim anahtarı istemciden değiştirilemez', function () {
    Livewire::test(StockOperation::class)->set('submissionKey', 'x');
})->throws(CannotUpdateLockedPropertyException::class);

it('pasif parça aramada yalnızca sayım için listelenir; pasif parçaya giriş reddedilir', function () {
    $inactive = Part::factory()->inactive()->create(['code' => 'PASIF-1']);

    $page = Livewire::test(StockOperation::class)->instance();
    expect(invade($page)->searchParts('PASIF', 'stock_in'))->toBe([])
        ->and(invade($page)->searchParts('PASIF', 'count_adjustment'))->toHaveKey($inactive->id);

    Livewire::test(StockOperation::class)
        ->fillForm(['type' => StockMovementType::StockIn->value, 'part_id' => $inactive->id, 'quantity' => '1'])
        ->call('save')
        ->assertNotified('İşlem yapılamadı');

    expect(movementsOf($inactive))->toBe(0);
});

it('parça sayfasından gelen parça önceden seçilir', function () {
    $this->get(StockOperation::getUrl(['part' => $this->part->id]))->assertOk();

    Livewire::withQueryParams(['part' => $this->part->id])
        ->test(StockOperation::class)
        ->assertSchemaStateSet(['part_id' => $this->part->id]);
});
