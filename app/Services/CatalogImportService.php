<?php

namespace App\Services;

use App\Enums\MotorPhase;
use App\Enums\PartType;
use App\Exceptions\BusinessRuleException;
use App\Imports\CatalogSheets;
use App\Imports\ImportResult;
use App\Imports\RollbackImport;
use App\Imports\SpreadsheetReader;
use App\Models\BomCoProduct;
use App\Models\BomItem;
use App\Models\Brand;
use App\Models\MotorModel;
use App\Models\Part;
use App\Models\PartSubtype;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ürün yapısını Excel şablonundan içe aktarır (CLAUDE.md Aşama E): birimler, ürünler, motor modelleri, reçeteler,
 * eş ürünler, açılış stoğu — bu sırayla ve yalnızca mevcut servislerle (tüm kurallar geçerli).
 *
 * Kontrol: aynı işlem bir transaction içinde gerçekten yapılır ve geri alınır; böylece önizleme aktarmayla birebir aynı
 * kuralları uygular. Aktarma: hata yoksa commit, tek satır bile hatalıysa hiçbir şey kaydedilmez. Her satır kendi
 * savepoint'inde çalışır; hatalı satır diğerlerini bozmaz, tüm hatalar tek seferde listelenir.
 *
 * Var olan kod: varsayılan atlanır; $update ise yalnızca dosyada dolu olan alanlar güncellenir. Marka ve alt tür yoksa
 * açılır; birim (Birimler sayfası dışında) ve depo tanımlı olmalıdır.
 */
class CatalogImportService
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PartService $parts,
        private readonly MotorModelService $models,
        private readonly BomService $boms,
        private readonly StockService $stock,
        private readonly BrandService $brands,
        private readonly PartSubtypeService $subtypes,
        private readonly UnitService $units,
    ) {}

    /** Dosyayı kontrol eder; hiçbir şey kaydedilmez. */
    public function check(string $path, User $user, bool $update = false): ImportResult
    {
        return $this->run($this->reader->read($path), $user, $update, commit: false);
    }

    /** Dosyayı aktarır: hata yoksa kaydedilir ($result->committed), varsa hiçbir şey kaydedilmez. */
    public function import(string $path, User $user, bool $update = false): ImportResult
    {
        return $this->run($this->reader->read($path), $user, $update, commit: true);
    }

    /**
     * Excel'den yapıştırılan reçete satırları (sekmeyle ayrılmış: bileşen kodu, net, fire %, brüt, fire miktarı, fire kartı).
     * Tek satır hatalıysa hiçbiri eklenmez.
     */
    public function pasteBom(Part $parent, string $text, User $user, bool $update = false): ImportResult
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($text)) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(fn ($c) => trim($c) === '' ? null : trim($c), str_getcsv($line, str_contains($line, "\t") ? "\t" : ';'));
            $rows[] = ['row' => $index + 1, 'values' => [
                'parent' => $parent->code, 'component' => $cells[0] ?? null, 'net' => $cells[1] ?? null, 'scrap_rate' => $cells[2] ?? null,
                'gross' => $cells[3] ?? null, 'scrap_quantity' => $cells[4] ?? null, 'scrap_part' => $cells[5] ?? null,
            ]];
        }

        if ($rows === []) {
            throw new BusinessRuleException('Yapıştırılan satır yok.');
        }

        return $this->run([CatalogSheets::BOM => $rows], $user, $update, commit: true);
    }

    /** @param  array<string, list<array{row: int, values: array<string, mixed>}>>  $sheets */
    private function run(array $sheets, User $user, bool $update, bool $commit): ImportResult
    {
        $result = new ImportResult;

        try {
            DB::transaction(function () use ($sheets, $user, $update, $commit, $result): void {
                foreach (array_keys(CatalogSheets::SHEETS) as $key) {
                    foreach ($sheets[$key] ?? [] as $row) {
                        $this->row($result, $key, $row['row'], $row['values'], $user, $update);
                    }
                }

                if (! $commit || $result->hasErrors()) {
                    throw new RollbackImport;
                }
            });

            $result->committed = true;
        } catch (RollbackImport) {
            // Kontrol veya hatalı aktarma: hiçbir şey kaydedilmedi.
        }

        return $result;
    }

    private function row(ImportResult $result, string $sheet, int $row, array $values, User $user, bool $update): void
    {
        // Metin alanlarında Excel'in sayıya çevirdiği değerler (barkod, kod, GTİP, gövde ölçüsü) metne döner.
        $quantities = ['allows_decimal', 'critical_level', 'net', 'scrap_rate', 'gross', 'scrap_quantity', 'quantity', 'power_kw', 'net_weight_kg', 'speed_rpm', 'frequency_hz', 'pole_count'];
        foreach ($values as $column => $value) {
            if (! in_array($column, $quantities, true)) {
                $values[$column] = $this->text($value);
            }
        }

        $key = (string) ($values['code'] ?? $values['name'] ?? (($values['parent'] ?? '').' › '.($values['component'] ?? $values['co'] ?? '')));

        try {
            [$status, $message] = DB::transaction(fn () => match ($sheet) {
                CatalogSheets::UNITS => $this->unit($values),
                CatalogSheets::PARTS => $this->part($values, $update, $result),
                CatalogSheets::MODELS => $this->model($values, $update, $result),
                CatalogSheets::BOM => $this->bomLine($values, $update),
                CatalogSheets::CO_PRODUCTS => $this->coProduct($values, $update),
                CatalogSheets::OPENING => $this->opening($values, $user),
            });
            $result->add($sheet, $row, $key, $status, $message);
        } catch (BusinessRuleException $exception) {
            $result->add($sheet, $row, $key, 'error', $exception->getMessage());
        } catch (QueryException $exception) {
            $result->add($sheet, $row, $key, 'error', $this->databaseMessage($exception));
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function unit(array $v): array
    {
        $name = $this->required($v, 'name', 'Ad');

        if ($this->findUnit($name) !== null) {
            return ['unchanged', 'Birim zaten var.'];
        }

        $this->units->create(['name' => $name, 'allows_decimal' => $this->bool($v['allows_decimal'] ?? null)]);

        return ['created', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function part(array $v, bool $update, ImportResult $result): array
    {
        $code = $this->required($v, 'code', 'Kod');
        $type = $this->type($this->required($v, 'type', 'Tür'));

        if ($type === PartType::FinishedGood) {
            throw new BusinessRuleException('Mamul bu sayfaya yazılmaz; "Motor modelleri" sayfasına yazın (mamul kartı modelle açılır).');
        }

        // Atlanacak satır hiçbir tanım (marka, alt tür) açmaz.
        $existing = Part::query()->where('code', $code)->first();

        if ($existing !== null && ! $update) {
            return ['skipped', null];
        }

        $data = array_filter([
            'name' => $v['name'] ?? null,
            'type' => $type,
            'unit_id' => $this->unitFor($this->required($v, 'unit', 'Birim'))->getKey(),
            'subtype_id' => filled($v['subtype'] ?? null) ? $this->subtypeFor($type, (string) $v['subtype'], $result)->getKey() : null,
            'brand_id' => filled($v['brand'] ?? null) ? $this->brandFor((string) $v['brand'], $result)->getKey() : null,
            'barcode' => isset($v['barcode']) ? (string) $v['barcode'] : null,
            'default_warehouse_id' => filled($v['warehouse'] ?? null) ? $this->warehouse((string) $v['warehouse'])->getKey() : null,
            'critical_level' => isset($v['critical_level']) ? $v['critical_level'] : null,
            'description' => $v['description'] ?? null,
        ], fn ($value) => $value !== null);

        if ($existing === null) {
            $this->required($v, 'name', 'Ad');
            $this->parts->create(['code' => $code, ...$data]);

            return ['created', null];
        }

        // Mamul kartında modelden yönetilen alanlar gönderilmez.
        if ($existing->isFinishedGood()) {
            unset($data['name'], $data['type'], $data['unit_id'], $data['subtype_id']);
        }

        $this->parts->update($existing, $data, $existing->lock_version);

        return ['updated', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function model(array $v, bool $update, ImportResult $result): array
    {
        $code = $this->required($v, 'code', 'Kod');
        $existing = MotorModel::query()->where('code', $code)->first();

        if ($existing !== null && ! $update) {
            return ['skipped', null];
        }

        $data = array_filter(array_intersect_key($v, array_flip(MotorModel::FIELDS)), fn ($value) => $value !== null);

        if (filled($v['phase'] ?? null)) {
            $data['phase'] = $this->phase((string) $v['phase']);
        }

        if (filled($v['subtype'] ?? null)) {
            $data['subtype_id'] = $this->subtypeFor(PartType::FinishedGood, (string) $v['subtype'], $result)->getKey();
        }

        foreach (['power_kw', 'net_weight_kg'] as $decimal) {
            if (isset($data[$decimal]) && is_float($data[$decimal])) {
                $data[$decimal] = Quantity::parse($data[$decimal]);
            }
        }

        if ($existing === null) {
            $this->required($v, 'name', 'Ad');
            $this->models->create(['code' => $code, ...$data]);

            return ['created', null];
        }

        $this->models->update($existing, $data, $existing->lock_version);

        return ['updated', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function bomLine(array $v, bool $update): array
    {
        $parent = $this->partByCode($this->required($v, 'parent', 'Üst ürün kodu'), 'Üst ürün');
        $component = $this->partByCode($this->required($v, 'component', 'Bileşen kodu'), 'Bileşen');
        $scrapPart = filled($v['scrap_part'] ?? null) ? $this->partByCode((string) $v['scrap_part'], 'Fire kartı') : null;

        if (filled($v['gross'] ?? null)) {
            $gross = Quantity::parse($v['gross']);
            $scrap = Quantity::parse($v['scrap_quantity'] ?? 0);

            if (filled($v['scrap_rate'] ?? null) && ! Quantity::isZero(Quantity::parse($v['scrap_rate']))) {
                throw new BusinessRuleException('Brüt miktarla fire oranı birlikte girilemez; fire miktarını yazın.');
            }

            if (Quantity::compare($scrap, $gross) >= 0) {
                throw new BusinessRuleException('Fire miktarı brüt miktardan küçük olmalıdır.');
            }

            [$net, $rate, $scrapQuantity] = [Quantity::sub($gross, $scrap), '0', $scrap];
        } else {
            $net = $this->required($v, 'net', 'Net miktar (veya Brüt miktar)');
            [$rate, $scrapQuantity] = [$v['scrap_rate'] ?? 0, null];
        }

        $existing = BomItem::query()->where('parent_part_id', $parent->getKey())->where('component_part_id', $component->getKey())->first();

        if ($existing === null) {
            $this->boms->add($parent, $component, $net, $rate, $scrapQuantity, $scrapPart);

            return ['created', null];
        }

        if (! $update) {
            return ['skipped', null];
        }

        $this->boms->update($existing, $net, $rate, $existing->lock_version, $scrapQuantity, $scrapPart);

        return ['updated', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function coProduct(array $v, bool $update): array
    {
        $parent = $this->partByCode($this->required($v, 'parent', 'Ana ürün kodu'), 'Ana ürün');
        $co = $this->partByCode($this->required($v, 'co', 'Eş ürün kodu'), 'Eş ürün');
        $quantity = $this->required($v, 'quantity', 'Birim başına');

        $existing = BomCoProduct::query()->where('parent_part_id', $parent->getKey())->where('co_part_id', $co->getKey())->first();

        if ($existing === null) {
            $this->boms->addCoProduct($parent, $co, $quantity);

            return ['created', null];
        }

        if (! $update) {
            return ['skipped', null];
        }

        $this->boms->updateCoProduct($existing, $quantity, $existing->lock_version);

        return ['updated', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function opening(array $v, User $user): array
    {
        $part = $this->partByCode($this->required($v, 'code', 'Ürün kodu'), 'Ürün');

        if ($part->isFinishedGood()) {
            throw new BusinessRuleException('Mamul (motor) açılış stoğu bu sayfayla girilemez.');
        }

        $warehouse = $this->warehouse($this->required($v, 'warehouse', 'Depo'));
        $description = filled($v['description'] ?? null) ? (string) $v['description'] : 'Açılış sayımı (Excel)';

        $movement = $this->stock->adjustCount($part, $this->required($v, 'quantity', 'Sayılan miktar'), $user, $description, warehouse: $warehouse);

        return $movement === null
            ? ['unchanged', 'Bakiye zaten doğru.']
            : ['created', 'Fark: '.Quantity::format((string) $movement->quantity, signed: true)];
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => sprintf('%.0f', $value),
            default => trim((string) $value),
        };
    }

    private function required(array $values, string $key, string $label): string|int|float
    {
        $value = $values[$key] ?? null;

        if ($value === null || $value === '') {
            throw new BusinessRuleException("{$label} zorunludur.");
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function type(string $value): PartType
    {
        $needle = CatalogSheets::normalize($value);

        foreach (PartType::cases() as $type) {
            if (in_array($needle, [CatalogSheets::normalize($type->value), CatalogSheets::normalize($type->label()), CatalogSheets::normalize($type->accountingCode()), CatalogSheets::normalize($type->getLabel())], true)) {
                return $type;
            }
        }

        throw new BusinessRuleException("Tür tanınmadı: \"{$value}\" (Hammadde, Komponent, Direkt sarf, Endirekt sarf, Yarı mamul).");
    }

    private function phase(string $value): MotorPhase
    {
        foreach (MotorPhase::cases() as $phase) {
            if (in_array(CatalogSheets::normalize($value), [CatalogSheets::normalize($phase->value), CatalogSheets::normalize($phase->label())], true)) {
                return $phase;
            }
        }

        throw new BusinessRuleException("Faz tanınmadı: \"{$value}\" (Trifaze / Monofaze).");
    }

    private function bool(mixed $value): bool
    {
        return in_array(CatalogSheets::normalize((string) $value), ['evet', 'e', 'var', '1', 'true', 'x'], true);
    }

    private function findUnit(string $name): ?Unit
    {
        return Unit::query()->whereRaw("translate(lower(btrim(name)), 'ıİI', 'iii') = translate(lower(btrim(?)), 'ıİI', 'iii')", [$name])->first();
    }

    private function unitFor(string $name): Unit
    {
        return $this->findUnit($name) ?? throw new BusinessRuleException("Birim tanımlı değil: \"{$name}\" (Birimler sayfasına ekleyin veya Birimler ekranından açın).");
    }

    private function brandFor(string $name, ImportResult $result): Brand
    {
        $brand = Brand::query()->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$name])->first();

        if ($brand === null) {
            $brand = $this->brands->create(['name' => $name]);
            $result->created[] = "Marka: {$brand->name}";
        }

        return $brand;
    }

    private function subtypeFor(PartType $type, string $name, ImportResult $result): PartSubtype
    {
        $subtype = PartSubtype::query()->where('type', $type->value)
            ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$name])->first();

        if ($subtype === null) {
            $subtype = $this->subtypes->create(['type' => $type, 'name' => $name]);
            $result->created[] = "Alt tür: {$subtype->fullLabel()}";
        }

        return $subtype;
    }

    private function warehouse(string $value): Warehouse
    {
        return Warehouse::query()
            ->where('code', mb_strtoupper(trim($value), 'UTF-8'))
            ->orWhereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$value])
            ->first() ?? throw new BusinessRuleException("Depo bulunamadı: \"{$value}\" (depo kodu veya adı).");
    }

    private function partByCode(string|int|float $code, string $label): Part
    {
        return Part::query()->where('code', trim((string) $code))->first()
            ?? throw new BusinessRuleException("{$label} bulunamadı: \"{$code}\" (önce Ürünler/Motor modelleri sayfasında tanımlayın).");
    }

    /** Veritabanı kural ihlalinin Türkçe mesajı (trigger mesajı) veya genel açıklama. */
    private function databaseMessage(QueryException $exception): string
    {
        if (preg_match('/ERROR:\s+(.+?)(?:\s+\(Connection:|\n|$)/u', $exception->getMessage(), $m)) {
            return str_contains($m[1], 'duplicate key') ? 'Aynı kayıt zaten var (tekil alan çakışması).' : $m[1];
        }

        return 'Veritabanı kuralı ihlal edildi.';
    }
}
