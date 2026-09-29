<?php

use App\Models\Unit;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UnitSeeder;

it('varsayılan birimleri doğru küsurat ayarıyla ekler', function () {
    $this->seed(UnitSeeder::class);

    expect(Unit::query()->orderBy('id')->pluck('allows_decimal', 'name')->all())->toBe([
        'Adet' => false,
        'Takım' => false,
        'Paket' => false,
        'Rulo' => false,
        'Kg' => true,
        'Metre' => true,
        'Litre' => true,
    ]);
});

it('tekrar çalıştırılabilir ve mevcut birimleri değiştirmez', function () {
    // Kullanıcı daha önce "kg" eklemiş ve küsuratı kapatmış; "Adet"i de küsuratlı yapmış.
    $kg = Unit::factory()->create(['name' => 'kg', 'allows_decimal' => false]);
    $adet = Unit::factory()->create(['name' => 'Adet', 'allows_decimal' => true]);
    $cm = Unit::factory()->create(['name' => 'cm', 'allows_decimal' => true]);

    $this->seed(UnitSeeder::class);
    $this->seed(UnitSeeder::class);

    expect(Unit::count())->toBe(count(UnitSeeder::DEFAULTS) + 1) // + "cm"
        ->and($kg->fresh())->name->toBe('kg')->allows_decimal->toBeFalse()->lock_version->toBe(0)
        ->and($adet->fresh())->allows_decimal->toBeTrue()->lock_version->toBe(0)
        ->and($cm->fresh()->exists)->toBeTrue()
        // Büyük/küçük harf farkı için ikinci bir birim oluşturulmaz.
        ->and(Unit::whereRaw('lower(name) = ?', ['kg'])->count())->toBe(1);
});

it('DatabaseSeeder varsayılan birimleri de ekler', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Unit::whereIn('name', array_keys(UnitSeeder::DEFAULTS))->count())->toBe(count(UnitSeeder::DEFAULTS));
});
