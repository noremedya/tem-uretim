<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasarımlar "Motor oluştur" gibi (karar 2026-11-10): motor görseli, motor tipi (mamul şablonu), motor bilgileri,
 * çevrildiği motor modeli; satırın şablondaki yeri (çizim numarası için). Şablon satırından gelen bileşende aynı ürün
 * farklı satırlarda olabilir (ön/arka rulman); elle eklenen satırda ürün tasarımda bir kez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motor_designs', function (Blueprint $table): void {
            $table->string('image_path')->nullable();
            $table->foreignId('bom_template_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('subtype_id')->nullable()->index()->constrained('part_subtypes')->restrictOnDelete();
            $table->string('phase', 20)->nullable();
            $table->decimal('power_kw', 10, 3)->nullable();
            $table->unsignedInteger('speed_rpm')->nullable();
            $table->unsignedSmallInteger('pole_count')->nullable();
            $table->string('voltage', 50)->nullable();
            $table->string('frame_size', 50)->nullable();
            $table->string('mounting_type', 50)->nullable();
            $table->foreignId('motor_model_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });

        Schema::table('motor_design_items', function (Blueprint $table): void {
            $table->foreignId('bom_template_line_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->dropUnique(['motor_design_id', 'part_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE motor_designs
                ADD CONSTRAINT motor_designs_phase_check CHECK (phase IS NULL OR phase IN ('single_phase', 'three_phase')),
                ADD CONSTRAINT motor_designs_power_check CHECK (power_kw IS NULL OR power_kw > 0),
                ADD CONSTRAINT motor_designs_speed_check CHECK (speed_rpm IS NULL OR speed_rpm > 0),
                ADD CONSTRAINT motor_designs_pole_check CHECK (pole_count IS NULL OR pole_count > 0);

            CREATE UNIQUE INDEX motor_design_items_manual_part_unique ON motor_design_items (motor_design_id, part_id)
                WHERE bom_template_line_id IS NULL AND part_id IS NOT NULL;
            CREATE UNIQUE INDEX motor_design_items_template_line_unique ON motor_design_items (motor_design_id, bom_template_line_id)
                WHERE bom_template_line_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS motor_design_items_manual_part_unique;
            DROP INDEX IF EXISTS motor_design_items_template_line_unique;
        SQL);

        Schema::table('motor_design_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bom_template_line_id');
            $table->unique(['motor_design_id', 'part_id']);
        });

        Schema::table('motor_designs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('motor_model_id');
            $table->dropConstrainedForeignId('subtype_id');
            $table->dropConstrainedForeignId('bom_template_id');
            $table->dropColumn(['image_path', 'phase', 'power_kw', 'speed_rpm', 'pole_count', 'voltage', 'frame_size', 'mounting_type']);
        });
    }
};
