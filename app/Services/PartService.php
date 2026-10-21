<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stok kartı yönetimi. Bakiye burada değişmez; yalnızca StockService değiştirir.
 */
class PartService
{
    private const FIELDS = ['code', 'name', 'unit_id', 'type', 'barcode', 'image_path', 'brand_id', 'description', 'default_warehouse_id', 'subtype_id'];

    /** Stok kartı görsellerinin diski ve klasörü (yerel; gece yedeğine dahil). */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'part-images';

    /** Mamul kartında motor modelinden yönetilen alanlar. */
    private const FINISHED_GOOD_MANAGED_FIELDS = ['code', 'name', 'unit_id', 'type', 'subtype_id'];

    public function __construct(
        private readonly StockService $stock,
        private readonly BomService $boms,
    ) {}

    /**
     * critical_level: varsayılan depodaki kritik seviye (diğer depolarınki ürün sayfasındaki depo bakiyelerinden).
     *
     * @param  array{code: string, name: string, unit_id: int, type: mixed, barcode?: ?string, critical_level?: string|int|float|null}  $data
     */
    public function create(array $data): Part
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data): Part {
            $part = new Part(Arr::only($data, self::FIELDS));

            if (! $part->type->isManuallyAssignable()) {
                throw new BusinessRuleException('Mamul kartı elle açılamaz; motor modeliyle birlikte oluşturulur.');
            }

            $part->is_active = true;
            $this->ensureSubtype($part);
            $this->ensureActiveBrand($part);
            $this->ensureActiveWarehouse($part);
            $part->save();

            $this->stock->openBalance($part, $data['critical_level'] ?? '0');

            return $part;
        });
    }

    /**
     * Motor modelinin mamul kartını açar (yalnızca MotorModelService çağırır). Birim "Adet"tir.
     */
    public function createFinishedGood(string $code, string $name, bool $isActive): Part
    {
        return DB::transaction(function () use ($code, $name, $isActive): Part {
            $part = new Part([
                'code' => $code,
                'name' => $name,
                'unit_id' => $this->pieceUnit()->getKey(),
                'type' => PartType::FinishedGood,
            ]);
            $part->is_active = $isActive;
            $part->save();

            $this->stock->openBalance($part);

            return $part;
        });
    }

    /** Mamul kartını motor modeliyle eşitler (kod, ad, aktiflik). Yalnızca MotorModelService çağırır. */
    public function syncFinishedGood(Part $part, string $code, string $name, bool $isActive): Part
    {
        $part->forceFill(['code' => $code, 'name' => $name, 'is_active' => $isActive]);
        $part->save();

        return $part;
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(Part $part, array $data, int $expectedLockVersion): Part
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($part, $data, $expectedLockVersion): Part {
            $originalType = PartType::from($part->getRawOriginal('type'));

            if ($originalType === PartType::FinishedGood) {
                $part->fill(Arr::only($data, array_diff(self::FIELDS, self::FINISHED_GOOD_MANAGED_FIELDS)));

                foreach (self::FINISHED_GOOD_MANAGED_FIELDS as $field) {
                    $value = $data[$field] ?? null;
                    $value = trim((string) ($value instanceof PartType ? $value->value : $value));

                    if (array_key_exists($field, $data) && $value !== (string) $part->getRawOriginal($field)) {
                        throw new BusinessRuleException('Mamul kartının kodu, adı, birimi ve türü motor modelinden yönetilir; mamul kartının türü değiştirilemez.');
                    }
                }
            } else {
                $part->fill(Arr::only($data, self::FIELDS));
            }

            if ($part->type !== $originalType) {
                $this->ensureTypeChangeAllowed($part, $originalType);

                // Alt tür ana türe bağlıdır: tür değişince eski türün alt türü temizlenir (denetim izine yazılır).
                if ($part->subtype_id !== null && $part->subtype?->type !== $part->type) {
                    $part->subtype_id = null;
                }
            }

            $this->ensureSubtype($part);

            if ($part->isDirty('unit_id')) {
                // Bakiye satırı kilitlenir: StockService hareket yazarken aynı satırı kilitlediği için
                // kontrol ile birim değişikliği arasında yeni hareket oluşamaz.
                StockBalance::query()->where('part_id', $part->getKey())->orderBy('warehouse_id')->lockForUpdate()->get();

                if ($part->stockMovements()->exists()) {
                    throw new BusinessRuleException('Hareketi olan ürünün birimi değiştirilemez.');
                }
            }

            $this->ensureActiveBrand($part);
            $this->ensureActiveWarehouse($part);

            $replacedImage = $part->isDirty('image_path') ? $part->getRawOriginal('image_path') : null;

            $part->saveExpectingVersion($expectedLockVersion);

            if (array_key_exists('critical_level', $data)) {
                $this->stock->setCriticalLevel($part, $data['critical_level']);
            }

            // Değiştirilen veya kaldırılan görselin dosyası, kayıt kesinleşince silinir.
            if ($replacedImage !== null) {
                DB::afterCommit(fn () => Storage::disk(self::IMAGE_DISK)->delete($replacedImage));
            }

            return $part;
        });
    }

    public function deactivate(Part $part): Part
    {
        $this->ensureNotFinishedGood($part);

        $part->is_active = false;
        $part->save();

        return $part;
    }

    public function activate(Part $part): Part
    {
        $this->ensureNotFinishedGood($part);

        $part->is_active = true;
        $part->save();

        return $part;
    }

    /**
     * Tür değiştirilebilir (yanlış sınıflamayı düzeltmek için, denetim izine yazılır); ancak mamul türüne
     * alınamaz ve reçete kurallarını bozamaz. Reçete kilidi altında kontrol edilir.
     */
    private function ensureTypeChangeAllowed(Part $part, PartType $originalType): void
    {
        if (! $originalType->isManuallyAssignable() || ! $part->type->isManuallyAssignable()) {
            throw new BusinessRuleException('Bir kart mamul türüne alınamaz ve mamul kartının türü değiştirilemez.');
        }

        $this->boms->lock();

        if (! $part->canHaveBom() && $part->bomItems()->exists()) {
            throw new BusinessRuleException('Bu kartın reçetesi var; türü yalnızca yarı mamul olabilir. Önce reçeteyi kaldırın.');
        }

        if ($part->type === PartType::IndirectConsumable && $part->usedInBomItems()->exists()) {
            throw new BusinessRuleException('Bu kart reçetelerde bileşen olarak kullanılıyor; endirekt sarf reçeteye giremez. Önce reçetelerden kaldırın.');
        }
    }

    private function ensureNotFinishedGood(Part $part): void
    {
        if ($part->isFinishedGood()) {
            throw new BusinessRuleException('Mamul kartı motor modeliyle birlikte aktifleşir/pasifleşir; motor modelini düzenleyin.');
        }
    }

    /** Mamul kartlarının birimi: "Adet" (yoksa tam sayılı olarak açılır). */
    private function pieceUnit(): Unit
    {
        return Unit::query()->whereRaw('lower(name) = ?', ['adet'])->first()
            ?? app(UnitService::class)->create(['name' => 'Adet', 'allows_decimal' => false]);
    }

    /** Pasif marka yeni seçilemez; ürünün mevcut (sonradan pasifleşen) markası korunur. */
    private function ensureActiveBrand(Part $part): void
    {
        if ($part->brand_id !== null && $part->isDirty('brand_id') && ! Brand::query()->whereKey($part->brand_id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Seçilen marka pasif veya bulunamadı.');
        }
    }

    /** Alt tür ürünün ana türüne ait olmalı (veritabanında da); pasif alt tür yeni seçilemez. */
    private function ensureSubtype(Part $part): void
    {
        if ($part->subtype_id === null || ! $part->isDirty('subtype_id')) {
            return;
        }

        $subtype = PartSubtype::query()->find($part->subtype_id);

        if ($subtype === null || ! $subtype->is_active) {
            throw new BusinessRuleException('Seçilen alt tür pasif veya bulunamadı.');
        }

        if ($subtype->type !== $part->type) {
            throw new BusinessRuleException("\"{$subtype->name}\" alt türü {$subtype->type->label()} türüne ait; ürünün türü {$part->type->label()}.");
        }

        $part->setRelation('subtype', $subtype);
    }

    /** Mamul kartının alt türü motor modelinden (yalnızca MotorModelService). */
    public function setFinishedGoodSubtype(Part $part, ?int $subtypeId): Part
    {
        $part->subtype_id = $subtypeId;
        $this->ensureSubtype($part);
        $part->save();

        return $part;
    }

    /** Pasif depo ürünün varsayılan deposu olarak yeni seçilemez. */
    private function ensureActiveWarehouse(Part $part): void
    {
        if ($part->default_warehouse_id !== null && $part->isDirty('default_warehouse_id')
            && ! Warehouse::query()->whereKey($part->default_warehouse_id)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Seçilen depo pasif veya bulunamadı.');
        }
    }

    private function normalize(array $data): array
    {
        if (array_key_exists('subtype_id', $data)) {
            $data['subtype_id'] = filled($data['subtype_id']) ? (int) $data['subtype_id'] : null;
        }

        if (array_key_exists('default_warehouse_id', $data)) {
            $data['default_warehouse_id'] = filled($data['default_warehouse_id']) ? (int) $data['default_warehouse_id'] : null;
        }

        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        if (array_key_exists('brand_id', $data)) {
            $data['brand_id'] = filled($data['brand_id']) ? (int) $data['brand_id'] : null;
        }

        if (array_key_exists('image_path', $data)) {
            $path = filled($data['image_path']) ? (string) $data['image_path'] : null;

            // Yalnızca görsel klasöründeki bir dosya (başka dosyaya, ör. rapor çıktılarına, yol verilemez).
            if ($path !== null && (! str_starts_with($path, self::IMAGE_DIRECTORY.'/') || str_contains($path, '..')
                || ! preg_match('/\.(jpe?g|png|webp)$/i', $path))) {
                throw new BusinessRuleException('Geçersiz görsel dosyası.');
            }

            $data['image_path'] = $path;
        }

        if (array_key_exists('critical_level', $data)) {
            $level = Quantity::parse($data['critical_level'] ?? 0);

            if (Quantity::compare($level, '0') < 0) {
                throw new BusinessRuleException('Kritik seviye negatif olamaz.');
            }

            $data['critical_level'] = $level;
        }

        return $data;
    }
}
