<?php

namespace App\Services;

use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Models\BomTemplate;
use App\Models\BomTemplateLine;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hazır reçete şablonları: tanımlama (satırlarıyla) ve bir ürünün reçetesine uygulama. Uygulama tek işlemdir:
 * herhangi bir satır reddedilirse (tür uyumsuz, miktar eksik, döngü…) hiçbir satır eklenmez. Ürün seçilmeyen satır
 * atlanır; reçetede zaten olan bileşen atlanır. Satırlar BomService üzerinden eklenir (tüm reçete kuralları geçerli).
 */
class BomTemplateService
{
    public function __construct(private readonly BomService $boms) {}

    /**
     * @param  array{name: string, product_type: mixed, description?: ?string, lines: list<array<string, mixed>>}  $data
     */
    public function create(array $data): BomTemplate
    {
        return DB::transaction(function () use ($data): BomTemplate {
            $template = new BomTemplate;
            $this->fill($template, $data);
            $template->save();
            $this->syncLines($template, $data['lines'] ?? []);

            return $template;
        });
    }

    /**
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(BomTemplate $template, array $data, int $expectedLockVersion): BomTemplate
    {
        return DB::transaction(function () use ($template, $data, $expectedLockVersion): BomTemplate {
            $this->fill($template, $data);
            $template->saveExpectingVersion($expectedLockVersion);

            if (array_key_exists('lines', $data)) {
                $this->syncLines($template, $data['lines']);
            }

            return $template;
        });
    }

    public function deactivate(BomTemplate $template): BomTemplate
    {
        $template->is_active = false;
        $template->save();

        return $template;
    }

    public function activate(BomTemplate $template): BomTemplate
    {
        $template->is_active = true;
        $template->save();

        return $template;
    }

    /**
     * Şablonu $parent'ın reçetesine uygular.
     *
     * @param  array<int, array{part_id?: mixed, quantity_per_unit?: mixed, scrap_rate?: mixed, gross_per_unit?: mixed, scrap_quantity_per_unit?: mixed, scrap_part_id?: mixed}>  $rows  şablon satırı id => girilen değerler
     * @return array{added: int, skipped: int}
     */
    public function apply(BomTemplate $template, Part $parent, array $rows): array
    {
        if (! $template->is_active) {
            throw new BusinessRuleException('Pasif şablon uygulanamaz.');
        }

        if ($template->product_type !== $parent->type) {
            throw new BusinessRuleException("\"{$template->name}\" şablonu {$template->product_type->label()} içindir; seçilen ürün {$parent->type->label()}.");
        }

        return DB::transaction(function () use ($template, $parent, $rows): array {
            $added = 0;
            $skipped = 0;
            $existing = $parent->bomItems()->pluck('component_part_id')->all();
            // Aynı ürünü seçen satırlar (ör. ön ve arka rulman aynı tip) tek reçete satırında toplanır.
            $entries = [];

            foreach ($template->lines()->with('subtype')->get() as $line) {
                $row = $rows[$line->getKey()] ?? [];

                if (blank($row['part_id'] ?? null)) {
                    $skipped++;

                    continue;
                }

                $part = Part::query()->findOrFail((int) $row['part_id']);
                $this->ensureMatches($line, $part);

                if ($line->isCoProduct()) {
                    $this->boms->addCoProduct($parent, $part, $this->required($row['quantity_per_unit'] ?? $line->quantity_per_unit, $line, 'Birim başına miktar'));
                    $added++;

                    continue;
                }

                if (in_array($part->getKey(), $existing, true)) {
                    $skipped++;

                    continue;
                }

                $entry = $this->entry($line, $part, $row);
                $previous = $entries[$part->getKey()] ?? null;

                if ($previous === null) {
                    $entries[$part->getKey()] = $entry;

                    continue;
                }

                if ($previous['scrap_quantity'] !== null || $entry['scrap_quantity'] !== null || Quantity::compare($previous['scrap_rate'], $entry['scrap_rate']) !== 0) {
                    throw new BusinessRuleException("{$previous['label']} ve {$line->label} aynı ürünü ({$part->code}) seçti; fireleri farklı olduğundan birleştirilemez.");
                }

                $entries[$part->getKey()]['quantity'] = Quantity::add($previous['quantity'], $entry['quantity']);
                $skipped++;
            }

            foreach ($entries as $partId => $entry) {
                $this->boms->add($parent, Part::query()->findOrFail($partId), $entry['quantity'], $entry['scrap_rate'], $entry['scrap_quantity'], $entry['scrap_part_id']);
                $added++;
            }

            if ($added === 0) {
                throw new BusinessRuleException('Hiçbir satıra ürün seçilmedi; reçeteye bir şey eklenmedi.');
            }

            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    /**
     * Satırın reçeteye girecek değerleri: net miktar ve fire (oran veya miktar).
     *
     * @return array{label: string, quantity: string, scrap_rate: string, scrap_quantity: ?string, scrap_part_id: ?int}
     */
    private function entry(BomTemplateLine $line, Part $part, array $row): array
    {
        if ($line->usesScrapQuantity() || filled($row['gross_per_unit'] ?? null)) {
            $gross = Quantity::parse($this->required($row['gross_per_unit'] ?? $line->gross_per_unit, $line, 'Brüt'));
            $scrap = Quantity::parse($row['scrap_quantity_per_unit'] ?? $line->scrap_quantity_per_unit ?? 0);

            if (Quantity::compare($scrap, $gross) >= 0) {
                throw new BusinessRuleException("{$line->label}: fire brüt miktardan küçük olmalıdır.");
            }

            return ['label' => $line->label, 'quantity' => Quantity::sub($gross, $scrap), 'scrap_rate' => '0', 'scrap_quantity' => $scrap,
                'scrap_part_id' => filled($row['scrap_part_id'] ?? null) ? (int) $row['scrap_part_id'] : null];
        }

        $quantity = $this->required($row['quantity_per_unit'] ?? $line->quantity_per_unit, $line, 'Miktar');

        if (! Quantity::isValid($quantity) || Quantity::compare(Quantity::parse($quantity), '0') <= 0) {
            throw new BusinessRuleException("{$line->label}: miktar sıfırdan büyük bir sayı olmalıdır.");
        }

        return ['label' => $line->label, 'quantity' => Quantity::parse($quantity), 'scrap_rate' => Quantity::parse($row['scrap_rate'] ?? $line->scrap_rate ?? 0),
            'scrap_quantity' => null, 'scrap_part_id' => null];
    }

    /** Satıra uyan ürünler: tür (ve alt tür) eşleşen aktif kartlar. */
    public function candidates(BomTemplateLine $line, ?Part $parent = null, string $search = ''): array
    {
        return $this->candidateParts($line, $parent, $search)
            ->mapWithKeys(fn (Part $p) => [$p->getKey() => self::candidateLabel($p)])
            ->all();
    }

    /** @return Collection<int, Part> Satırın tür ve alt türündeki aktif ürünler (en fazla 50). */
    public function candidateParts(BomTemplateLine $line, ?Part $parent = null, string $search = ''): Collection
    {
        return Part::query()
            ->with('unit')
            ->where('is_active', true)
            ->where('type', $line->component_type->value)
            ->when($line->component_subtype_id, fn ($q, $id) => $q->where('subtype_id', $id))
            ->when($parent, fn ($q) => $q->whereKeyNot($parent->getKey()))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")))
            ->orderBy('code')
            ->limit(50)
            ->get();
    }

    public static function candidateLabel(Part $part): string
    {
        return "{$part->code} — {$part->name} ({$part->unit->name})";
    }

    public function ensureMatches(BomTemplateLine $line, Part $part): void
    {
        if ($part->type !== $line->component_type) {
            throw new BusinessRuleException("{$line->label}: {$part->code} bir {$part->type->label()}; bu satıra {$line->component_type->label()} seçilmeli.");
        }

        if ($line->component_subtype_id !== null && $part->subtype_id !== $line->component_subtype_id) {
            throw new BusinessRuleException("{$line->label}: {$part->code} \"{$line->subtype->name}\" alt türünde değil.");
        }
    }

    private function required(mixed $value, BomTemplateLine $line, string $label): string
    {
        if ($value === null || $value === '') {
            throw new BusinessRuleException("{$line->label}: {$label} girilmelidir.");
        }

        return (string) $value;
    }

    private function fill(BomTemplate $template, array $data): void
    {
        $name = trim((string) ($data['name'] ?? $template->name));
        $type = $data['product_type'] ?? $template->product_type;
        $type = $type instanceof PartType ? $type : PartType::tryFrom((string) $type);

        if ($name === '') {
            throw new BusinessRuleException('Şablon adı zorunludur.');
        }

        if (! in_array($type, [PartType::SemiFinished, PartType::FinishedGood], true)) {
            throw new BusinessRuleException('Şablon yarı mamul veya mamul için olmalıdır.');
        }

        if (BomTemplate::query()->where('name', $name)->when($template->exists, fn ($q) => $q->whereKeyNot($template->getKey()))->exists()) {
            throw new BusinessRuleException("\"{$name}\" adlı bir şablon zaten var.");
        }

        $drawing = array_key_exists('drawing', $data) ? (filled($data['drawing']) ? (string) $data['drawing'] : null) : $template->drawing;

        if ($drawing !== null && config("assembly_drawings.{$drawing}") === null) {
            throw new BusinessRuleException("Montaj çizimi tanımlı değil: {$drawing}.");
        }

        $template->forceFill([
            'name' => $name,
            'drawing' => $drawing,
            'product_type' => $type,
            'description' => array_key_exists('description', $data) ? (filled($data['description']) ? trim($data['description']) : null) : $template->description,
        ]);
    }

    /** Satırlar verilen listeyle değiştirilir (şablon satırları tanım verisidir; reçeteleri etkilemez). */
    private function syncLines(BomTemplate $template, array $lines): void
    {
        if ($lines === []) {
            throw new BusinessRuleException('Şablonda en az bir satır olmalıdır.');
        }

        $template->lines()->delete();

        foreach (array_values($lines) as $order => $line) {
            $kind = ($line['kind'] ?? BomTemplateLine::KIND_COMPONENT) === BomTemplateLine::KIND_CO_PRODUCT ? BomTemplateLine::KIND_CO_PRODUCT : BomTemplateLine::KIND_COMPONENT;
            $type = PartType::tryFrom((string) ($line['component_type'] ?? '')) ?? throw new BusinessRuleException('Satır '.($order + 1).': tür seçilmelidir.');
            $label = trim((string) ($line['label'] ?? ''));

            if ($label === '') {
                throw new BusinessRuleException('Satır '.($order + 1).': satır adı zorunludur.');
            }

            if ($kind === BomTemplateLine::KIND_CO_PRODUCT && $type !== PartType::SemiFinished) {
                throw new BusinessRuleException("{$label}: eş ürün yarı mamul olmalıdır.");
            }

            if ($kind === BomTemplateLine::KIND_COMPONENT && in_array($type, [PartType::IndirectConsumable, PartType::FinishedGood], true)) {
                throw new BusinessRuleException("{$label}: {$type->label()} reçeteye bileşen olarak girmez.");
            }

            $subtypeId = filled($line['component_subtype_id'] ?? null) ? (int) $line['component_subtype_id'] : null;

            if ($subtypeId !== null && PartSubtype::query()->find($subtypeId)?->type !== $type) {
                throw new BusinessRuleException("{$label}: alt tür satırın türüne ait olmalıdır.");
            }

            $gross = filled($line['gross_per_unit'] ?? null) ? Quantity::parse($line['gross_per_unit']) : null;
            $scrapQuantity = filled($line['scrap_quantity_per_unit'] ?? null) ? Quantity::parse($line['scrap_quantity_per_unit']) : null;

            if ($gross !== null && $scrapQuantity !== null && Quantity::compare($scrapQuantity, $gross) >= 0) {
                throw new BusinessRuleException("{$label}: fire brüt miktardan küçük olmalıdır.");
            }

            $number = filled($line['drawing_number'] ?? null) ? (int) $line['drawing_number'] : null;

            if ($number !== null && ($number < 1 || $number > 99)) {
                throw new BusinessRuleException("{$label}: çizimdeki parça no 1–99 arasında olmalıdır.");
            }

            $model = new BomTemplateLine;
            $model->forceFill([
                'bom_template_id' => $template->getKey(),
                'kind' => $kind,
                'label' => $label,
                'component_type' => $type,
                'component_subtype_id' => $subtypeId,
                'quantity_per_unit' => $gross === null && filled($line['quantity_per_unit'] ?? null) ? Quantity::parse($line['quantity_per_unit']) : null,
                'scrap_rate' => $gross === null ? Quantity::parse($line['scrap_rate'] ?? 0) : '0',
                'gross_per_unit' => $gross,
                'scrap_quantity_per_unit' => $gross === null ? null : $scrapQuantity,
                'drawing_number' => $number,
                'sort_order' => $order + 1,
            ])->save();
        }
    }
}
