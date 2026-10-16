<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stok kartı türleri 6'ya çıkar (muhasebe kodlarıyla, CLAUDE.md bölüm 4).
 * Mevcut "consumable" (sarf) kartları direkt sarfa geçer; endirekt olanlar formdan düzeltilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE parts DROP CONSTRAINT parts_type_check');
        DB::table('parts')->where('type', 'consumable')->update(['type' => 'direct_consumable']);
        DB::statement(<<<'SQL'
            ALTER TABLE parts ADD CONSTRAINT parts_type_check CHECK (type IN (
                'raw_material', 'component', 'direct_consumable', 'indirect_consumable', 'semi_finished', 'finished_good'
            ))
        SQL);
    }

    public function down(): void
    {
        if (DB::table('parts')->whereIn('type', ['semi_finished', 'finished_good'])->exists()) {
            throw new RuntimeException('Yarı mamul veya mamul kartı varken bu migration geri alınamaz.');
        }

        DB::statement('ALTER TABLE parts DROP CONSTRAINT parts_type_check');
        DB::table('parts')->whereIn('type', ['direct_consumable', 'indirect_consumable'])->update(['type' => 'consumable']);
        DB::statement("ALTER TABLE parts ADD CONSTRAINT parts_type_check CHECK (type IN ('raw_material', 'component', 'consumable'))");
    }
};
