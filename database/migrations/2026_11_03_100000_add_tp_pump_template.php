<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TP serisi bor yağı pompası: motor şablonu ve montaj çizimi (Tem Motor "TP serisi boryağı pompası montaj bağlantı
 * listesi", config/assembly_drawings.php "tp-pompa"). Pompa parçaları için alt türler. Çizimdeki 22 numara listede
 * olmadığı için şablona alınmadı. Vida ve pul adetleri bilinmediğinden boştur (ekranda girilir).
 */
return new class extends Migration
{
    private const SUBTYPES = [
        ['component', 'Pompa pervanesi'],
        ['component', 'Boru'],
        ['semi_finished', 'Salyangoz kapak'],
        ['semi_finished', 'Flanş'],
        ['finished_good', 'Bor yağı pompası'],
    ];

    private const NAME = 'TP serisi bor yağı pompası';

    public function up(): void
    {
        foreach (self::SUBTYPES as [$type, $name]) {
            $exists = DB::table('part_subtypes')->where('type', $type)
                ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$name])->exists();

            if (! $exists) {
                DB::table('part_subtypes')->insert(['type' => $type, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (DB::table('bom_templates')->where('name', self::NAME)->exists()) {
            return;
        }

        // [çizim no, satır adı, tür, alt tür, miktar]; miktar null = ekranda girilir.
        $lines = [
            [1, 'Uç bağlantı kutusu', 'component', 'Klemens kutusu', '1'],
            [2, 'Rotor (işlenmiş, balansı alınmış, milli)', 'semi_finished', 'Mil+rotor', '1'],
            [3, 'Sargılı vernikli stator', 'semi_finished', 'Bobinajlı stator', '1'],
            [4, 'Kör boru', 'component', 'Boru', '1'],
            [5, 'Sıvı yolu borusu', 'component', 'Boru', '1'],
            [6, 'Pompa pervanesi', 'component', 'Pompa pervanesi', '1'],
            [7, 'Salyangoz kapak', 'semi_finished', 'Salyangoz kapak', '1'],
            [8, 'Salyangoz bağlantı flanşı', 'semi_finished', 'Flanş', '1'],
            [9, 'Gövde', 'semi_finished', 'Gövde', null],
            [10, 'Motor bağlantı flanşı', 'semi_finished', 'Flanş', '1'],
            [11, 'Üst rulman (bilyalı)', 'component', 'Rulman', '1'],
            [11, 'Alt rulman (bilyalı)', 'component', 'Rulman', '1'],
            [12, 'Salyangoz kapak vidaları', 'direct_consumable', 'Bağlantı elemanı', null],
            [13, 'Klemens bağlantı vidası', 'direct_consumable', 'Bağlantı elemanı', null],
            [14, 'Pul (klemens vidası)', 'direct_consumable', 'Bağlantı elemanı', null],
            [15, 'Uç bağlantı kutu vidası', 'direct_consumable', 'Bağlantı elemanı', null],
            [16, 'Pul (kutu vidası)', 'direct_consumable', 'Bağlantı elemanı', null],
            [17, 'Klemens (köprü, somun ve pullar dahil)', 'component', 'Klemens', '1'],
            [18, 'Kablo giriş rakoru', 'component', 'Kablo rakoru', '1'],
            [19, 'Kablo giriş rakor contası', 'component', 'Conta', '1'],
            [20, 'Conta', 'component', 'Conta', '1'],
            [null, 'Etiket', 'direct_consumable', null, '1'],
        ];

        $templateId = DB::table('bom_templates')->insertGetId([
            'name' => self::NAME,
            'product_type' => 'finished_good',
            'description' => 'TP serisi bor yağı pompası (Tem Motor montaj bağlantı listesi). Vida ve pul adetlerini girin.',
            'drawing' => 'tp-pompa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $order => [$number, $label, $type, $subtypeName, $quantity]) {
            DB::table('bom_template_lines')->insert([
                'bom_template_id' => $templateId,
                'kind' => 'component',
                'label' => $label,
                'component_type' => $type,
                'component_subtype_id' => $subtypeName === null ? null : DB::table('part_subtypes')->where('type', $type)->where('name', $subtypeName)->value('id'),
                'quantity_per_unit' => $quantity,
                'scrap_rate' => '0',
                'drawing_number' => $number,
                'sort_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $templateId = DB::table('bom_templates')->where('name', self::NAME)->value('id');

        if ($templateId !== null) {
            DB::table('bom_template_lines')->where('bom_template_id', $templateId)->delete();
            DB::table('bom_templates')->where('id', $templateId)->delete();
        }
    }
};
