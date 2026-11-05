<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasarımlar (deneme reçetesi): taslak motor tasarımı ve bileşen satırları. Stoktan düşmez, iş emri açılamaz, motor
 * modeline bağlı değildir. Satır tanımlı bir ürün ya da henüz kartı olmayan deneme bileşeni (serbest metin) olabilir;
 * satırlar serbestçe eklenip çıkarılır. Tasarım silinmez, pasifleştirilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_designs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('motor_design_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('motor_design_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('description', 150)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('unit_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['motor_design_id', 'part_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE motor_designs
                ADD CONSTRAINT motor_designs_code_check CHECK (btrim(code) <> ''),
                ADD CONSTRAINT motor_designs_name_check CHECK (btrim(name) <> '');

            ALTER TABLE motor_design_items
                ADD CONSTRAINT motor_design_items_quantity_check CHECK (quantity > 0),
                -- Ürün satırında birim üründendir; deneme bileşeninde ad ve birim zorunlu.
                ADD CONSTRAINT motor_design_items_kind_check CHECK (
                    (part_id IS NOT NULL AND unit_id IS NULL)
                    OR (part_id IS NULL AND description IS NOT NULL AND btrim(description) <> '' AND unit_id IS NOT NULL));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_design_items');
        Schema::dropIfExists('motor_designs');
    }
};
