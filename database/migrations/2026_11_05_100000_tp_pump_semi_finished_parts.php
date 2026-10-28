<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TP serisi bor yağı pompası (firma bilgisi): salyangoz kapak, flanşlar ve borular fabrikada hammaddeden (kg) yapılan
 * yarı mamuldür; pompa pervanesi dışarıdan gelen yarı mamuldür (adet, fason stok girişiyle). Boru ve Pompa pervanesi
 * alt türleri yarı mamule taşınır (kullanılıyorsa yarı mamulde aynı adla yenisi açılır), pompa şablonu satırları
 * güncellenir ve hammaddeden yarı mamul şablonları eklenir (hammadde miktarı ekranda girilir).
 */
return new class extends Migration
{
    private const PUMP = 'TP serisi bor yağı pompası';

    public function up(): void
    {
        $boru = $this->semiFinishedSubtype('Boru');
        $pervane = $this->semiFinishedSubtype('Pompa pervanesi');
        $templateId = DB::table('bom_templates')->where('name', self::PUMP)->value('id');

        if ($templateId !== null) {
            $lines = DB::table('bom_template_lines')->where('bom_template_id', $templateId);
            (clone $lines)->whereIn('label', ['Kör boru', 'Sıvı yolu borusu'])->update(['component_type' => 'semi_finished', 'component_subtype_id' => $boru, 'updated_at' => now()]);
            (clone $lines)->where('label', 'Pompa pervanesi')->update(['component_type' => 'semi_finished', 'component_subtype_id' => $pervane, 'updated_at' => now()]);
            DB::table('bom_templates')->where('id', $templateId)->update([
                'description' => 'TP serisi bor yağı pompası (Tem Motor montaj bağlantı listesi). Salyangoz kapak, flanşlar ve borular hammaddeden üretilen, pompa pervanesi dışarıdan gelen yarı mamuldür. Vida, pul ve O-ring adetlerini girin.',
                'updated_at' => now(),
            ]);
        }

        $templates = [
            'Pompa salyangoz kapağı' => 'Salyangoz kapak',
            'Pompa flanşı' => 'Flanş',
            'Pompa borusu' => 'Boru',
        ];

        foreach ($templates as $name => $subtype) {
            if (DB::table('bom_templates')->where('name', $name)->exists()) {
                continue;
            }

            $id = DB::table('bom_templates')->insertGetId([
                'name' => $name,
                'product_type' => 'semi_finished',
                'description' => "Bor yağı pompası parçası ({$subtype}): hammaddeden (kg) üretilir. Hammaddeyi seçin, birim başına kg miktarını girin.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('bom_template_lines')->insert([
                'bom_template_id' => $id,
                'kind' => 'component',
                'label' => 'Hammadde (kg)',
                'component_type' => 'raw_material',
                'component_subtype_id' => null,
                'quantity_per_unit' => null,
                'scrap_rate' => '0',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Alt türü yarı mamule taşır; komponentte kullanılıyorsa yarı mamulde aynı adla yenisini açar. */
    private function semiFinishedSubtype(string $name): int
    {
        $existing = DB::table('part_subtypes')->where('type', 'semi_finished')->where('name', $name)->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $component = DB::table('part_subtypes')->where('type', 'component')->where('name', $name)->value('id');

        if ($component !== null && ! DB::table('parts')->where('subtype_id', $component)->exists()) {
            DB::table('part_subtypes')->where('id', $component)->update(['type' => 'semi_finished', 'updated_at' => now()]);

            return $component;
        }

        return DB::table('part_subtypes')->insertGetId(['type' => 'semi_finished', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $ids = DB::table('bom_templates')->whereIn('name', ['Pompa salyangoz kapağı', 'Pompa flanşı', 'Pompa borusu'])->pluck('id');
        DB::table('bom_template_lines')->whereIn('bom_template_id', $ids)->delete();
        DB::table('bom_templates')->whereIn('id', $ids)->delete();
    }
};
