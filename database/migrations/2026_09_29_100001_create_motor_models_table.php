<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_models', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            // Teknik alanlar; hepsi isteğe bağlı.
            $table->decimal('power_kw', 10, 3)->nullable();
            $table->unsignedInteger('speed_rpm')->nullable();
            $table->string('voltage', 50)->nullable();
            $table->unsignedSmallInteger('frequency_hz')->nullable();
            $table->unsignedSmallInteger('pole_count')->nullable();
            $table->string('frame_size', 50)->nullable();
            $table->string('phase', 20)->nullable();
            $table->string('mounting_type', 50)->nullable();
            $table->string('protection_class', 20)->nullable();
            $table->string('efficiency_class', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE motor_models
                ADD CONSTRAINT motor_models_code_not_blank_check CHECK (btrim(code) <> ''),
                ADD CONSTRAINT motor_models_name_not_blank_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT motor_models_phase_check CHECK (phase IS NULL OR phase IN ('single_phase', 'three_phase')),
                ADD CONSTRAINT motor_models_power_kw_check CHECK (power_kw IS NULL OR power_kw > 0),
                ADD CONSTRAINT motor_models_speed_rpm_check CHECK (speed_rpm IS NULL OR speed_rpm > 0),
                ADD CONSTRAINT motor_models_frequency_hz_check CHECK (frequency_hz IS NULL OR frequency_hz > 0),
                ADD CONSTRAINT motor_models_pole_count_check CHECK (pole_count IS NULL OR pole_count > 0),
                ADD CONSTRAINT motor_models_lock_version_check CHECK (lock_version >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_models');
    }
};
