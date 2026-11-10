<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sarf malzemeleri (karar 2026-11-10): boya, koli, köpük, balonlu poşet motor reçetesinden motor başına düşer (direkt
 * sarf; motor şablonlarına satır, miktar ekranda girilir). Vernik zaten bobinajlı stator reçetesinde (motor başına bir
 * stator). Şerit bant ve koli bandı reçeteye girmez: endirekt sarf, depodan toplu gider çıkışıyla düşer.
 */
return new class extends Migration
{
    private const SUBTYPES = [
        'direct_consumable' => ['Boya', 'Koli', 'Köpük', 'Balonlu poşet'],
        'indirect_consumable' => ['Bant'],
    ];

    private const MOTOR_TEMPLATES = ['Trifaze asenkron motor', 'Monofaze MD motor', 'Monofaze MSD motor', 'Vibrasyon motoru'];

    private const LINES = [['Boya', 'Boya'], ['Koli', 'Koli'], ['Köpük', 'Köpük'], ['Balonlu poşet', 'Balonlu poşet']];

    public function up(): void
    {
        foreach (self::SUBTYPES as $type => $names) {
            foreach ($names as $name) {
                DB::statement('INSERT INTO part_subtypes (type, name, is_active, lock_version, created_at, updated_at) VALUES (?, ?, true, 0, now(), now())
                    ON CONFLICT DO NOTHING', [$type, $name]);
            }
        }

        foreach (self::MOTOR_TEMPLATES as $templateName) {
            $templateId = DB::table('bom_templates')->where('name', $templateName)->value('id');

            if ($templateId === null) {
                continue;
            }

            $order = (int) DB::table('bom_template_lines')->where('bom_template_id', $templateId)->max('sort_order');

            foreach (self::LINES as [$label, $subtype]) {
                if (DB::table('bom_template_lines')->where('bom_template_id', $templateId)->where('label', $label)->exists()) {
                    continue;
                }

                DB::table('bom_template_lines')->insert([
                    'bom_template_id' => $templateId, 'kind' => 'component', 'label' => $label, 'component_type' => 'direct_consumable',
                    'component_subtype_id' => DB::table('part_subtypes')->where('type', 'direct_consumable')->where('name', $subtype)->value('id'),
                    'quantity_per_unit' => null, 'scrap_rate' => '0', 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $templateIds = DB::table('bom_templates')->whereIn('name', self::MOTOR_TEMPLATES)->pluck('id');
        DB::table('bom_template_lines')->whereIn('bom_template_id', $templateIds)->whereIn('label', array_column(self::LINES, 0))->delete();
    }
};
