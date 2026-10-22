<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama D — Reçete: fire miktarla (birim başına; brüt = net + fire) veya oranla; isteğe bağlı fire kartı (fire
 * hurda olarak stoğa girer); eş ürünler (bir yarı mamulle aynı işlemde çıkan başka yarı mamuller, ör. aynı kalıpta
 * basılan stator ve rotor paketi). İş emri bunları oluştururken kopyalar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Reçete satırı: fire miktarı ve fire kartı ---
        Schema::table('bom_items', function (Blueprint $table): void {
            $table->decimal('scrap_quantity_per_unit', 15, 3)->nullable();
            $table->foreignId('scrap_part_id')->nullable()->index()->constrained('parts')->restrictOnDelete();
        });

        Schema::table('work_order_materials', function (Blueprint $table): void {
            $table->decimal('scrap_quantity_per_unit', 15, 3)->nullable();
            $table->foreignId('scrap_part_id')->nullable()->index()->constrained('parts')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            -- Fire ya oranla ya miktarla: ikisi birden olmaz.
            ALTER TABLE bom_items ADD CONSTRAINT bom_items_scrap_quantity_check
                CHECK (scrap_quantity_per_unit IS NULL OR (scrap_quantity_per_unit >= 0 AND scrap_rate = 0));
            ALTER TABLE bom_items ADD CONSTRAINT bom_items_scrap_part_check
                CHECK (scrap_part_id IS NULL OR (scrap_part_id <> component_part_id AND scrap_part_id <> parent_part_id));
            ALTER TABLE work_order_materials ADD CONSTRAINT work_order_materials_scrap_quantity_check
                CHECK (scrap_quantity_per_unit IS NULL OR (scrap_quantity_per_unit >= 0 AND scrap_rate = 0));

            CREATE OR REPLACE FUNCTION bom_items_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                scrap parts%ROWTYPE;
            BEGIN
                IF (SELECT type FROM parts WHERE id = NEW.parent_part_id) NOT IN ('semi_finished', 'finished_good') THEN
                    RAISE EXCEPTION 'Reçete yalnızca yarı mamul veya mamul kartına tanımlanabilir.' USING ERRCODE = 'check_violation';
                END IF;

                IF (SELECT type FROM parts WHERE id = NEW.component_part_id) IN ('indirect_consumable', 'finished_good') THEN
                    RAISE EXCEPTION 'Endirekt sarf ve mamul reçeteye bileşen olarak eklenemez.' USING ERRCODE = 'check_violation';
                END IF;

                -- Eş ürün yalnızca ana ürünüyle üretilir; kendi reçetesi olamaz.
                IF EXISTS (SELECT 1 FROM bom_co_products WHERE co_part_id = NEW.parent_part_id) THEN
                    RAISE EXCEPTION 'Eş ürünün kendi reçetesi olamaz; ana ürünüyle birlikte üretilir.' USING ERRCODE = 'check_violation';
                END IF;

                -- Fire kartı: mamul olamaz, bileşenle aynı birimde olmalı.
                IF NEW.scrap_part_id IS NOT NULL THEN
                    SELECT * INTO scrap FROM parts WHERE id = NEW.scrap_part_id;

                    IF scrap.type = 'finished_good' OR scrap.unit_id <> (SELECT unit_id FROM parts WHERE id = NEW.component_part_id) THEN
                        RAISE EXCEPTION 'Fire kartı mamul olamaz ve malzemeyle aynı birimde olmalıdır.' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        // --- Eş ürünler ---
        Schema::create('bom_co_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_part_id')->index()->constrained('parts')->restrictOnDelete();
            // Bir ürün yalnızca bir ana ürünün eş ürünüdür.
            $table->foreignId('co_part_id')->unique()->constrained('parts')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 3);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bom_co_products
                ADD CONSTRAINT bom_co_products_quantity_check CHECK (quantity_per_unit > 0),
                ADD CONSTRAINT bom_co_products_not_self_check CHECK (parent_part_id <> co_part_id);

            CREATE OR REPLACE FUNCTION bom_co_products_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT type FROM parts WHERE id = NEW.parent_part_id) <> 'semi_finished'
                    OR (SELECT type FROM parts WHERE id = NEW.co_part_id) <> 'semi_finished' THEN
                    RAISE EXCEPTION 'Eş ürün ve ana ürünü yarı mamul olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM bom_items WHERE parent_part_id = NEW.co_part_id) THEN
                    RAISE EXCEPTION 'Reçetesi olan ürün eş ürün olamaz.' USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM bom_co_products WHERE co_part_id = NEW.parent_part_id) THEN
                    RAISE EXCEPTION 'Bir eş ürünün kendi eş ürünü olamaz.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bom_co_products_validate BEFORE INSERT OR UPDATE ON bom_co_products
                FOR EACH ROW EXECUTE FUNCTION bom_co_products_validate();
            SQL);

        // --- İş emrinin eş ürünleri (oluştururken reçeteden kopyalanır) ---
        Schema::create('work_order_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 3);
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
            $table->unique(['work_order_id', 'part_id']);
        });

        DB::statement('ALTER TABLE work_order_outputs ADD CONSTRAINT work_order_outputs_quantity_check CHECK (quantity_per_unit > 0 AND quantity > 0)');
    }

    public function down(): void
    {
        throw new RuntimeException('Reçete fire ve eş ürün geçişi geri alınamaz.');
    }
};
