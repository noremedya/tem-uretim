<?php

namespace App\Services;

use App\Enums\MotorPhase;
use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\MotorDesign;
use App\Models\MotorDesignItem;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Support\Quantity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tasarımlar (deneme reçetesi). Stok ve iş emriyle bağı yoktur: satırlar serbestçe eklenir, değiştirilir ve silinir.
 * Satır tanımlı bir ürün (reçetedeki gibi: mamul ve endirekt sarf olamaz) ya da kartı henüz açılmamış deneme bileşeni
 * (ad + birim) olabilir. Tasarım silinmez, pasifleştirilir; pasif tasarımın satırları değişmez.
 * - "Bileşen seçerek tasarla" (karar 2026-11-10): motor tipi (mamul şablonu) seçilir, montaj çizimindeki her satıra
 *   ürün veya kartsız deneme bileşeni girilir; tasarım ve satırları tek işlemde oluşur. Şablon satırından gelen bileşende
 *   aynı ürün farklı satırlarda olabilir (ön/arka rulman); elle eklenen satırda ürün bir kez.
 * - Motor görseli: yerel diskte (design-images), yalnızca tasarımı görebilen oturumlu kullanıcıya gösterilir.
 * - "Motor modeline çevir": motor modeli, mamul kartı ve reçetesi tek işlemde oluşur (MotorModelService, şablon satırları
 *   BomTemplateService::apply ile — aynı ürün tek reçete satırında toplanır; elle eklenen ürünler BomService ile).
 *   Kartsız deneme bileşenleri reçeteye girmez. Bir tasarım bir kez çevrilir.
 */
class MotorDesignService
{
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'design-images';

    private const FIELDS = ['code', 'name', 'description', 'image_path', 'subtype_id', 'phase', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type'];

    public function __construct(
        private readonly MotorModelService $models,
        private readonly BomTemplateService $templates,
        private readonly BomService $boms,
    ) {}

    public function create(array $data): MotorDesign
    {
        return DB::transaction(function () use ($data): MotorDesign {
            $design = new MotorDesign(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($design);
            $design->save();

            return $design;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(MotorDesign $design, array $data, int $expectedLockVersion): MotorDesign
    {
        return DB::transaction(function () use ($design, $data, $expectedLockVersion): MotorDesign {
            $design->fill(Arr::only($this->normalize($data), self::FIELDS));
            $this->ensureValid($design);
            $replaced = $design->isDirty('image_path') ? $design->getRawOriginal('image_path') : null;
            $design->saveExpectingVersion($expectedLockVersion);

            if ($replaced !== null) {
                DB::afterCommit(fn () => Storage::disk(self::IMAGE_DISK)->delete($replaced));
            }

            return $design;
        });
    }

    /**
     * Bileşen seçerek tasarla: şablonun bileşen satırlarına ürün (part_id) veya deneme bileşeni (description + unit_id)
     * ve miktar. Hiç bileşen yoksa veya bir satır reddedilirse tasarım da oluşmaz.
     *
     * @param  array<string, mixed>  $data  tasarım alanları
     * @param  array<int|string, array{part_id?: mixed, description?: mixed, unit_id?: mixed, quantity?: mixed}>  $rows  şablon satırı id => seçim
     */
    public function build(BomTemplate $template, array $data, array $rows): MotorDesign
    {
        if ($template->product_type !== PartType::FinishedGood || ! $template->is_active) {
            throw new BusinessRuleException('Tasarım için aktif bir motor (mamul) şablonu seçin.');
        }

        return DB::transaction(function () use ($template, $data, $rows): MotorDesign {
            $design = $this->create($data);
            $design->bom_template_id = $template->getKey();
            $design->save();
            $added = 0;

            foreach ($template->lines()->where('kind', BomTemplateLine::KIND_COMPONENT)->with('subtype')->orderBy('id')->get() as $line) {
                $row = $rows[$line->getKey()] ?? [];

                if (blank($row['part_id'] ?? null) && blank($row['description'] ?? null)) {
                    continue;
                }

                $item = new MotorDesignItem;
                $item->motor_design_id = $design->getKey();
                $item->bom_template_line_id = $line->getKey();

                try {
                    $this->fillItem($item, ['quantity' => $row['quantity'] ?? null, ...Arr::only($row, ['part_id', 'description', 'unit_id'])]);

                    if ($item->part_id !== null) {
                        $this->templates->ensureMatches($line, Part::query()->findOrFail($item->part_id));
                    }
                } catch (BusinessRuleException $exception) {
                    throw new BusinessRuleException(($line->drawingLabel() ? "{$line->drawingLabel()} · " : '')."{$line->label}: ".$exception->getMessage());
                }

                $item->save();
                $added++;
            }

            if ($added === 0) {
                throw new BusinessRuleException('En az bir bileşen seçin veya yazın.');
            }

            return $design;
        });
    }

    /**
     * Tasarımdan motor modeli, mamul kartı ve reçetesi (tek işlem). Kartsız deneme bileşenleri atlanır.
     *
     * @param  array<string, mixed>  $model  motor modeli alanları (boşsa tasarımdan)
     * @return array{model: MotorModel, added: int, skipped: int}
     */
    public function convertToModel(MotorDesign $design, array $model = []): array
    {
        return DB::transaction(function () use ($design, $model): array {
            $design = MotorDesign::query()->lockForUpdate()->findOrFail($design->getKey());

            if ($design->motor_model_id !== null) {
                throw new BusinessRuleException('Bu tasarımdan zaten motor modeli oluşturuldu.');
            }

            $items = $design->items()->with(['part', 'templateLine'])->orderBy('id')->get();
            $withPart = $items->whereNotNull('part_id');

            if ($withPart->isEmpty()) {
                throw new BusinessRuleException('Tasarımda stok kartı olan bileşen yok; önce deneme bileşenleri için ürün kartı açıp seçin.');
            }

            $fields = [...Arr::only($design->only(['code', 'name', 'subtype_id', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type']),
                ['code', 'name', 'subtype_id', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type']),
                'phase' => $design->phase?->value, 'description' => $design->description, ...array_filter($model, fn ($v) => filled($v))];
            $created = $this->models->create($fields);
            $finished = $created->finishedPart()->firstOrFail();
            $added = 0;

            $templateItems = $withPart->whereNotNull('bom_template_line_id');

            if ($design->template !== null && $templateItems->isNotEmpty()) {
                $rows = $templateItems->mapWithKeys(fn (MotorDesignItem $i) => [$i->bom_template_line_id => [
                    'part_id' => $i->part_id, 'quantity_per_unit' => (string) $i->quantity,
                ]])->all();
                $added += $this->templates->apply($design->template, $finished, $rows)['added'];
            }

            foreach ($withPart->whereNull('bom_template_line_id') as $item) {
                if (! $finished->bomItems()->where('component_part_id', $item->part_id)->exists()) {
                    $this->boms->add($finished, $item->part, (string) $item->quantity);
                    $added++;
                }
            }

            $design->motor_model_id = $created->getKey();
            $design->save();

            return ['model' => $created, 'added' => $added, 'skipped' => $items->count() - $withPart->count()];
        });
    }

    public function deactivate(MotorDesign $design): MotorDesign
    {
        $design->is_active = false;
        $design->save();

        return $design;
    }

    public function activate(MotorDesign $design): MotorDesign
    {
        $design->is_active = true;
        $design->save();

        return $design;
    }

    /**
     * @param  array{part_id?: ?int, description?: ?string, quantity: mixed, unit_id?: ?int, notes?: ?string}  $data
     */
    public function addItem(MotorDesign $design, array $data): MotorDesignItem
    {
        return DB::transaction(function () use ($design, $data): MotorDesignItem {
            $this->lockActive($design);

            $item = new MotorDesignItem;
            $item->motor_design_id = $design->getKey();
            $this->fillItem($item, $data);
            $item->save();

            return $item;
        });
    }

    public function updateItem(MotorDesignItem $item, array $data): MotorDesignItem
    {
        return DB::transaction(function () use ($item, $data): MotorDesignItem {
            $this->lockActive($item->design);
            $this->fillItem($item, $data);
            $item->save();

            return $item;
        });
    }

    public function removeItem(MotorDesignItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->lockActive($item->design);
            $item->delete();
        });
    }

    private function fillItem(MotorDesignItem $item, array $data): void
    {
        $partId = filled($data['part_id'] ?? null) ? (int) $data['part_id'] : null;
        $description = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

        if (($partId === null) === ($description === null)) {
            throw new BusinessRuleException('Bir ürün seçin ya da deneme bileşeninin adını yazın (ikisinden yalnızca biri).');
        }

        $quantity = Quantity::parse((string) ($data['quantity'] ?? ''));

        if (bccomp($quantity, '0', Quantity::SCALE) <= 0) {
            throw new BusinessRuleException('Miktar sıfırdan büyük olmalıdır.');
        }

        if ($partId !== null) {
            $part = Part::query()->with('unit')->find($partId) ?? throw new BusinessRuleException('Ürün bulunamadı.');

            if (in_array($part->type, [PartType::FinishedGood, PartType::IndirectConsumable], true)) {
                throw new BusinessRuleException("{$part->type->label()} tasarıma bileşen olarak eklenemez.");
            }

            if (! $part->is_active && $partId !== $item->getOriginal('part_id')) {
                throw new BusinessRuleException("{$part->code} {$part->name} pasif; tasarıma eklenemez.");
            }

            $duplicate = $item->bom_template_line_id === null && MotorDesignItem::query()->where('motor_design_id', $item->motor_design_id)
                ->where('part_id', $partId)->whereNull('bom_template_line_id')
                ->when($item->exists, fn ($q) => $q->whereKeyNot($item->getKey()))->exists();

            if ($duplicate) {
                throw new BusinessRuleException("{$part->code} {$part->name} bu tasarımda zaten var; miktarını değiştirin.");
            }

            $unit = $part->unit;
            $item->forceFill(['part_id' => $partId, 'description' => null, 'unit_id' => null]);
        } else {
            $unit = Unit::query()->find($data['unit_id'] ?? 0) ?? throw new BusinessRuleException('Deneme bileşeninin birimi zorunludur.');
            $item->forceFill(['part_id' => null, 'description' => mb_substr($description, 0, 150), 'unit_id' => $unit->getKey()]);
        }

        if (! $unit->allows_decimal && ! Quantity::isWhole($quantity)) {
            throw new BusinessRuleException("{$unit->name} biriminde miktar tam sayı olmalıdır.");
        }

        $item->forceFill(['quantity' => $quantity, 'notes' => $notes === null ? null : mb_substr($notes, 0, 500)]);
    }

    private function lockActive(MotorDesign $design): void
    {
        $locked = MotorDesign::query()->lockForUpdate()->findOrFail($design->getKey());

        if (! $locked->is_active) {
            throw new BusinessRuleException('Pasif tasarımın bileşenleri değiştirilemez; önce aktifleştirin.');
        }
    }

    private function ensureValid(MotorDesign $design): void
    {
        if ($design->code === '') {
            throw new BusinessRuleException('Tasarım kodu zorunludur.');
        }

        if ($design->name === '') {
            throw new BusinessRuleException('Tasarım adı zorunludur.');
        }

        $duplicate = MotorDesign::query()->where('code', $design->code)
            ->when($design->exists, fn ($q) => $q->whereKeyNot($design->getKey()))->exists();

        if ($duplicate) {
            throw new BusinessRuleException("{$design->code} kodlu bir tasarım zaten var.");
        }
    }

    private function normalize(array $data): array
    {
        foreach (['description', 'voltage', 'frame_size', 'mounting_type'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim((string) $data[$field]) : null;
            }
        }

        if (array_key_exists('image_path', $data)) {
            $path = filled($data['image_path']) ? (string) $data['image_path'] : null;

            // Yalnızca tasarım görselleri klasöründeki bir resim (başka dosyaya yol verilemez).
            if ($path !== null && (! str_starts_with($path, self::IMAGE_DIRECTORY.'/') || str_contains($path, '..') || ! preg_match('/\.(jpe?g|png|webp)$/i', $path))) {
                throw new BusinessRuleException('Geçersiz görsel dosyası.');
            }

            $data['image_path'] = $path;
        }

        if (array_key_exists('phase', $data)) {
            $phase = $data['phase'] instanceof MotorPhase ? $data['phase'] : (filled($data['phase']) ? MotorPhase::tryFrom((string) $data['phase']) : null);

            if (filled($data['phase']) && $phase === null) {
                throw new BusinessRuleException('Geçersiz faz.');
            }

            $data['phase'] = $phase;
        }

        if (array_key_exists('power_kw', $data)) {
            $data['power_kw'] = filled($data['power_kw']) ? Quantity::parse((string) $data['power_kw']) : null;

            if ($data['power_kw'] !== null && Quantity::compare($data['power_kw'], '0') <= 0) {
                throw new BusinessRuleException('Güç sıfırdan büyük olmalıdır.');
            }
        }

        foreach (['speed_rpm' => 'Devir', 'pole_count' => 'Kutup sayısı'] as $field => $label) {
            if (array_key_exists($field, $data)) {
                if (blank($data[$field])) {
                    $data[$field] = null;
                } elseif (filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $field === 'pole_count' ? 32767 : PHP_INT_MAX]]) === false) {
                    throw new BusinessRuleException("{$label} pozitif bir tam sayı olmalıdır.");
                } else {
                    $data[$field] = (int) $data[$field];
                }
            }
        }

        if (array_key_exists('subtype_id', $data)) {
            $data['subtype_id'] = filled($data['subtype_id']) ? (int) $data['subtype_id'] : null;

            if ($data['subtype_id'] !== null && ! PartSubtype::query()->whereKey($data['subtype_id'])->where('type', PartType::FinishedGood->value)->exists()) {
                throw new BusinessRuleException('Alt tür mamul alt türlerinden seçilmelidir.');
            }
        }

        return $data;
    }
}
