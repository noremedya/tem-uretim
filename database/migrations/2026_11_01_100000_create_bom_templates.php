<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hazır reçete şablonları: bir ürün türü (yarı mamul veya mamul) için satır listesi. Her satır hangi tür (ve isteğe
 * bağlı alt tür) üründen seçileceğini, varsayılan miktarı ve fireyi taşır; şablon uygulanırken her satıra gerçek ürün
 * seçilir. Satır bileşen veya eş ürün olabilir. Kurulumda firmanın anlattığı yapıya göre örnek şablonlar açılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('product_type', 30);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('bom_template_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_template_id')->index()->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('component');
            $table->string('label', 150);
            $table->string('component_type', 30);
            $table->foreignId('component_subtype_id')->nullable()->index()->constrained('part_subtypes')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 3)->nullable();
            $table->decimal('scrap_rate', 5, 2)->default(0);
            $table->decimal('gross_per_unit', 15, 3)->nullable();
            $table->decimal('scrap_quantity_per_unit', 15, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bom_templates
                ADD CONSTRAINT bom_templates_product_type_check CHECK (product_type IN ('semi_finished', 'finished_good')),
                ADD CONSTRAINT bom_templates_name_check CHECK (btrim(name) <> '');

            ALTER TABLE bom_template_lines
                ADD CONSTRAINT bom_template_lines_kind_check CHECK (kind IN ('component', 'co_product')),
                ADD CONSTRAINT bom_template_lines_label_check CHECK (btrim(label) <> ''),
                -- Bileşen endirekt sarf ve mamul olamaz; eş ürün yalnızca yarı mamul.
                ADD CONSTRAINT bom_template_lines_type_check CHECK (
                    (kind = 'component' AND component_type IN ('raw_material', 'component', 'direct_consumable', 'semi_finished'))
                    OR (kind = 'co_product' AND component_type = 'semi_finished')),
                ADD CONSTRAINT bom_template_lines_quantity_check CHECK (
                    (quantity_per_unit IS NULL OR quantity_per_unit > 0)
                    AND scrap_rate >= 0 AND scrap_rate < 100
                    AND (gross_per_unit IS NULL OR gross_per_unit > 0)
                    AND (scrap_quantity_per_unit IS NULL OR scrap_quantity_per_unit >= 0)
                    -- Fire ya oranla ya miktarla (brüt/fire).
                    AND (gross_per_unit IS NULL OR (scrap_rate = 0 AND quantity_per_unit IS NULL))
                    AND (scrap_quantity_per_unit IS NULL OR gross_per_unit IS NULL OR scrap_quantity_per_unit < gross_per_unit));

            -- Alt tür satırın türüne ait olmalı.
            CREATE OR REPLACE FUNCTION bom_template_lines_subtype_rule() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.component_subtype_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM part_subtypes s WHERE s.id = NEW.component_subtype_id AND s.type = NEW.component_type
                ) THEN
                    RAISE EXCEPTION 'Şablon satırının alt türü, satırın türüne ait olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bom_template_lines_subtype_rule BEFORE INSERT OR UPDATE ON bom_template_lines
                FOR EACH ROW EXECUTE FUNCTION bom_template_lines_subtype_rule();
            SQL);

        $this->seedTemplates();
    }

    private function seedTemplates(): void
    {
        // [satır adı, tür, alt tür adı, miktar, fire %, brüt, fire miktarı, tür (component/co_product)]
        $motorCommon = static fn (string $terminal, array $extra = [], bool $fan = true): array => array_values(array_filter([
            ['Bobinajlı stator', 'semi_finished', 'Bobinajlı stator', '1'],
            ['Mil+rotor', 'semi_finished', 'Mil+rotor', '1'],
            ['Gövde', 'semi_finished', 'Gövde', '1'],
            ['Kapak', 'semi_finished', 'Kapak', '2'],
            ['Rulman', 'component', 'Rulman', '2'],
            ["Klemens kutusu ({$terminal})", 'component', 'Klemens kutusu', '1'],
            ...$extra,
            $fan ? ['Pervane', 'component', 'Pervane', '1'] : null,
            ['Pervane koruma tası', 'component', 'Pervane koruma tası', '1'],
            ['Civata', 'direct_consumable', 'Bağlantı elemanı', '8', '2'],
            ['Etiket', 'direct_consumable', null, '1'],
        ]));

        $templates = [
            ['Trifaze asenkron motor', 'finished_good', 'Trifaze motor montajı.', $motorCommon('trifaze')],
            ['Monofaze MD motor', 'finished_good', 'Monofaze, tek kondansatörlü motor montajı.', $motorCommon('monofaze', [['Kondansatör', 'component', 'Kondansatör', '1']])],
            ['Monofaze MSD motor', 'finished_good', 'Monofaze, iki kondansatörlü motor montajı.', $motorCommon('monofaze', [['Kondansatör', 'component', 'Kondansatör', '2']])],
            ['Vibrasyon motoru', 'finished_good', 'Pervanesiz; koruma tası eksantrik saçları korur. Eksantrik saç adedini girin.', $motorCommon('trifaze', [['Eksantrik saç', 'component', 'Eksantrik saç', null]], fan: false)],
            ['Stator paketi (pres)', 'semi_finished', 'Stator ve rotor paketi aynı kalıpta basılır; sac brüt/fire ağırlığıyla, fire hurda kartına. Örnek: 71 tip 70 mm brüt 6,61 kg, fire 2,88 kg.', [
                ['Silisli sac', 'raw_material', 'Silisli sac', null, '0', '6.61', '2.88'],
                ['Rotor paketi (eş ürün)', 'semi_finished', 'Rotor paketi', '1', '0', null, null, 'co_product'],
            ]],
            ['Bobinajlı stator', 'semi_finished', 'Bobinaj: stator paketinden çeker. Bakır, makaron, kablo ve vernik miktarlarını girin.', [
                ['Stator paketi', 'semi_finished', 'Stator paketi', '1'],
                ['Bakır tel (kg)', 'raw_material', 'Bakır tel', null],
                ['Makaron (m)', 'direct_consumable', 'Makaron', null],
                ['Kablo (m)', 'direct_consumable', 'Kablo', null],
                ['Vernik (kg)', 'direct_consumable', 'Vernik', null],
            ]],
            ['Enjekte rotor', 'semi_finished', 'Rotor paketine alüminyum enjeksiyonu. Alüminyum miktarını girin.', [
                ['Rotor paketi', 'semi_finished', 'Rotor paketi', '1'],
                ['Alüminyum (kg)', 'raw_material', 'Alüminyum', null],
            ]],
            ['İşlenmiş mil', 'semi_finished', 'Mil çeliğinden talaşlı imalat; brüt ve talaş (fire) ağırlığıyla. Örnek: brüt 2,2 kg, fire 0,5 kg.', [
                ['Mil çeliği', 'raw_material', 'Mil çeliği', null, '0', '2.2', '0.5'],
            ]],
            ['Mil+rotor', 'semi_finished', 'Enjekte rotor ve işlenmiş mil montajı (sonrasında talaşlı imalat).', [
                ['Enjekte rotor', 'semi_finished', 'Enjekte rotor', '1'],
                ['İşlenmiş mil', 'semi_finished', 'İşlenmiş mil', '1'],
            ]],
        ];

        $subtype = fn (string $type, ?string $name) => $name === null ? null
            : DB::table('part_subtypes')->where('type', $type)->where('name', $name)->value('id');

        foreach ($templates as [$name, $productType, $description, $lines]) {
            $templateId = DB::table('bom_templates')->insertGetId([
                'name' => $name, 'product_type' => $productType, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (array_values($lines) as $order => $line) {
                [$label, $type, $subtypeName, $quantity] = $line;
                DB::table('bom_template_lines')->insert([
                    'bom_template_id' => $templateId,
                    'kind' => $line[7] ?? 'component',
                    'label' => $label,
                    'component_type' => $type,
                    'component_subtype_id' => $subtype($type, $subtypeName),
                    'quantity_per_unit' => ($line[5] ?? null) !== null ? null : $quantity,
                    'scrap_rate' => $line[4] ?? '0',
                    'gross_per_unit' => $line[5] ?? null,
                    'scrap_quantity_per_unit' => $line[6] ?? null,
                    'sort_order' => $order + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS bom_template_lines_subtype_rule ON bom_template_lines; DROP FUNCTION IF EXISTS bom_template_lines_subtype_rule()');
        Schema::dropIfExists('bom_template_lines');
        Schema::dropIfExists('bom_templates');
    }
};
