<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Varsayılan birimler. Tekrar çalıştırılabilir: yalnızca eksik birimleri ekler, mevcut birimleri
 * (küsurat ayarı dahil) değiştirmez. Ad karşılaştırması büyük/küçük harf duyarsızdır; elle eklenmiş
 * "kg" varken ayrıca "Kg" oluşturulmaz.
 */
class UnitSeeder extends Seeder
{
    /** @var array<string, bool> ad => küsuratlı mı */
    public const DEFAULTS = [
        'Adet' => false,
        'Takım' => false,
        'Paket' => false,
        'Rulo' => false,
        'Kg' => true,
        'Metre' => true,
        'Litre' => true,
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $name => $allowsDecimal) {
            Unit::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->firstOr(fn () => Unit::create(['name' => $name, 'allows_decimal' => $allowsDecimal]));
        }
    }
}
