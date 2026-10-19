<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stok kartı yönetimi. Bakiye burada değişmez; yalnızca StockService değiştirir.
 */
class PartService
{
    private const FIELDS = ['code', 'name', 'unit_id', 'type', 'barcode', 'critical_level', 'image_path'];

    /** Stok kartı görsellerinin diski ve klasörü (yerel; gece yedeğine dahil). */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'part-images';

    /** Mamul kartında motor modelinden yönetilen alanlar. */
    private const FINISHED_GOOD_MANAGED_FIELDS = ['code', 'name', 'unit_id', 'type'];

    public function __construct(
        private readonly StockService $stock,
        private readonly BomService $boms,
    ) {}

    /**
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
            $part->save();

            $this->stock->openBalance($part);

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
                'critical_level' => '0',
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
            }

            if ($part->isDirty('unit_id')) {
                // Bakiye satırı kilitlenir: StockService hareket yazarken aynı satırı kilitlediği için
                // kontrol ile birim değişikliği arasında yeni hareket oluşamaz.
                StockBalance::query()->where('part_id', $part->getKey())->lockForUpdate()->first();

                if ($part->stockMovements()->exists()) {
                    throw new BusinessRuleException('Hareketi olan parçanın birimi değiştirilemez.');
                }
            }

            $replacedImage = $part->isDirty('image_path') ? $part->getRawOriginal('image_path') : null;

            $part->saveExpectingVersion($expectedLockVersion);

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

    private function normalize(array $data): array
    {
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
