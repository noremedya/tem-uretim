<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Motor oluştur" ekranı: Gram birimi; montaj çizimi (şablon) ve çizimdeki parça numarası (şablon satırı);
 * montaj parça listesindeki bileşenler için alt türler. Trifaze, Monofaze MD (daimi kondansatörlü) ve Monofaze MSD
 * (start ve daimi kondansatörlü, merkezkaç anahtarlı) şablonları Tem Motor B3 montaj parça listesine göre yeniden
 * yazılır (şablon satırları tanım verisidir; mevcut reçeteleri etkilemez).
 */
return new class extends Migration
{
    private const COMPONENT_SUBTYPES = [
        'Klemens kutusu kapağı', 'Klemens', 'Conta', 'V-ring', 'Kablo rakoru',
        'Daimi kondansatör', 'Start kondansatörü', 'Merkezkaç anahtarı',
    ];

    public function up(): void
    {
        if (! DB::table('units')->whereRaw('lower(name) = ?', ['gram'])->exists()) {
            DB::table('units')->insert(['name' => 'Gram', 'allows_decimal' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (self::COMPONENT_SUBTYPES as $name) {
            $exists = DB::table('part_subtypes')->where('type', 'component')
                ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$name])->exists();

            if (! $exists) {
                DB::table('part_subtypes')->insert(['type' => 'component', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('bom_templates', function (Blueprint $table): void {
            // config/assembly_drawings.php anahtarı (ör. b3-trifaze).
            $table->string('drawing', 50)->nullable();
        });

        Schema::table('bom_template_lines', function (Blueprint $table): void {
            $table->unsignedSmallInteger('drawing_number')->nullable();
        });

        DB::statement('ALTER TABLE bom_template_lines ADD CONSTRAINT bom_template_lines_drawing_number_check CHECK (drawing_number IS NULL OR drawing_number BETWEEN 1 AND 99)');

        $this->rewriteMotorTemplates();
    }

    private function rewriteMotorTemplates(): void
    {
        // [çizim no, satır adı, tür, alt tür, miktar, fire %]; miktar null = ekranda girilir.
        $b3 = [
            [1, 'Gövde', 'semi_finished', 'Gövde', null],
            [2, 'Ön kapak', 'semi_finished', 'Kapak', '1'],
            [3, 'Arka kapak', 'semi_finished', 'Kapak', '1'],
            [4, 'Sargılı vernikli stator', 'semi_finished', 'Bobinajlı stator', '1'],
            [5, 'Rotor (işlenmiş, balansı alınmış, milli)', 'semi_finished', 'Mil+rotor', '1'],
            [6, 'Soğutma pervanesi', 'component', 'Pervane', '1'],
            [7, 'Pervane muhafaza tası', 'component', 'Pervane koruma tası', '1'],
            [8, 'Uç bağlantı kutusu', 'component', 'Klemens kutusu', '1'],
            [9, 'Uç bağlantı kutu kapağı', 'component', 'Klemens kutusu kapağı', '1'],
            [10, 'Conta (kutu altı)', 'component', 'Conta', '1'],
            [11, 'Conta (kutu kapağı)', 'component', 'Conta', '1'],
            [12, 'Lastik toz contası (V-ring)', 'component', 'V-ring', '2'],
            [13, 'Klemens (köprü, somun ve pullar dahil)', 'component', 'Klemens', '1'],
            [14, 'Kablo giriş rakoru', 'component', 'Kablo rakoru', '1'],
            [15, 'Ön rulman', 'component', 'Rulman', '1'],
            [15, 'Arka rulman', 'component', 'Rulman', '1'],
        ];
        $consumables = [
            [null, 'Civata', 'direct_consumable', 'Bağlantı elemanı', '8', '2'],
            [null, 'Etiket', 'direct_consumable', null, '1'],
        ];
        $permanent = [null, 'Daimi kondansatör', 'component', 'Daimi kondansatör', '1'];

        $templates = [
            'Trifaze asenkron motor' => ['B3 trifaze motor (Tem Motor montaj parça listesi).', [...$b3, ...$consumables]],
            'Monofaze MD motor' => ['Monofaze, daimi kondansatörlü motor (B3 montaj parça listesi + kondansatör).', [...$b3, $permanent, ...$consumables]],
            'Monofaze MSD motor' => ['Monofaze, start ve daimi kondansatörlü motor; merkezkaç anahtarlı (B3 montaj parça listesi + kondansatörler).', [
                ...$b3,
                [null, 'Start kondansatörü', 'component', 'Start kondansatörü', '1'],
                $permanent,
                [null, 'Merkezkaç anahtarı', 'component', 'Merkezkaç anahtarı', '1'],
                ...$consumables,
            ]],
        ];

        $subtype = fn (string $type, ?string $name) => $name === null ? null
            : DB::table('part_subtypes')->where('type', $type)->where('name', $name)->value('id');

        foreach ($templates as $name => [$description, $lines]) {
            $templateId = DB::table('bom_templates')->where('name', $name)->value('id');

            if ($templateId === null) {
                continue;
            }

            DB::table('bom_templates')->where('id', $templateId)->update(['drawing' => 'b3-trifaze', 'description' => $description, 'updated_at' => now()]);
            DB::table('bom_template_lines')->where('bom_template_id', $templateId)->delete();

            foreach ($lines as $order => [$number, $label, $type, $subtypeName, $quantity]) {
                DB::table('bom_template_lines')->insert([
                    'bom_template_id' => $templateId,
                    'kind' => 'component',
                    'label' => $label,
                    'component_type' => $type,
                    'component_subtype_id' => $subtype($type, $subtypeName),
                    'quantity_per_unit' => $quantity,
                    'scrap_rate' => $lines[$order][5] ?? '0',
                    'drawing_number' => $number,
                    'sort_order' => $order + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bom_template_lines DROP CONSTRAINT IF EXISTS bom_template_lines_drawing_number_check');
        Schema::table('bom_template_lines', fn (Blueprint $table) => $table->dropColumn('drawing_number'));
        Schema::table('bom_templates', fn (Blueprint $table) => $table->dropColumn('drawing'));
    }
};
