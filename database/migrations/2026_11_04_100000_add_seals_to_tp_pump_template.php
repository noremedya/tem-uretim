<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TP serisi bor yağı pompası şablonuna keçe (çizimde 22) ve O-ring (çizimde numarasız; adedi ekranda girilir).
 * Etiket satırı sonda kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['Keçe', 'O-ring'] as $name) {
            $exists = DB::table('part_subtypes')->where('type', 'component')
                ->whereRaw("translate(lower(btrim(name)), 'ı', 'i') = translate(lower(btrim(?)), 'ı', 'i')", [$name])->exists();

            if (! $exists) {
                DB::table('part_subtypes')->insert(['type' => 'component', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $templateId = DB::table('bom_templates')->where('name', 'TP serisi bor yağı pompası')->value('id');

        if ($templateId === null || DB::table('bom_template_lines')->where('bom_template_id', $templateId)->where('label', 'Keçe')->exists()) {
            return;
        }

        $last = (int) DB::table('bom_template_lines')->where('bom_template_id', $templateId)->max('sort_order');
        DB::table('bom_template_lines')->where('bom_template_id', $templateId)->where('label', 'Etiket')->update(['sort_order' => $last + 3]);

        foreach ([[22, 'Keçe', '1'], [null, 'O-ring', null]] as $offset => [$number, $label, $quantity]) {
            DB::table('bom_template_lines')->insert([
                'bom_template_id' => $templateId,
                'kind' => 'component',
                'label' => $label,
                'component_type' => 'component',
                'component_subtype_id' => DB::table('part_subtypes')->where('type', 'component')->where('name', $label)->value('id'),
                'quantity_per_unit' => $quantity,
                'scrap_rate' => '0',
                'drawing_number' => $number,
                'sort_order' => $last + 1 + $offset,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $templateId = DB::table('bom_templates')->where('name', 'TP serisi bor yağı pompası')->value('id');
        DB::table('bom_template_lines')->where('bom_template_id', $templateId)->whereIn('label', ['Keçe', 'O-ring'])->delete();
    }
};
