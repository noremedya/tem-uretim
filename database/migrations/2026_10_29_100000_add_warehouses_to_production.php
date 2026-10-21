<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama B2 — Üretimde depolar: iş emrinin kaynak (malzeme) ve hedef (çıktı) deposu, malzeme satırının çekildiği
 * depo, motorun bulunduğu depo, alış faturası satırının giriş deposu; mamulde motor seçimiyle transfer.
 *
 * Mevcut kayıtlar B1 davranışıyla doldurulur: kaynak deposu boş (her malzeme kendi deposundan), hedef ve malzeme
 * deposu ürünün varsayılan deposu, motorlar mamulün varsayılan deposunda.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ürünün varsayılan deposu: kendi deposu, yoksa türünün aktif deposu.
        $defaultWarehouse = fn (string $part) => "COALESCE({$part}.default_warehouse_id, (SELECT w.id FROM warehouses w WHERE w.is_active AND w.default_part_types @> to_jsonb({$part}.type::text) ORDER BY w.id LIMIT 1))";

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('source_warehouse_id')->nullable()->index()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('target_warehouse_id')->nullable()->index()->constrained('warehouses')->restrictOnDelete();
        });
        DB::statement("UPDATE work_orders wo SET target_warehouse_id = {$defaultWarehouse('p')} FROM parts p WHERE p.id = wo.product_part_id");
        DB::statement('ALTER TABLE work_orders ALTER COLUMN target_warehouse_id SET NOT NULL');

        Schema::table('work_order_materials', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::statement("UPDATE work_order_materials m SET warehouse_id = {$defaultWarehouse('p')} FROM parts p WHERE p.id = m.part_id");
        DB::statement('ALTER TABLE work_order_materials ALTER COLUMN warehouse_id SET NOT NULL');

        Schema::table('motor_units', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::statement("UPDATE motor_units u SET warehouse_id = {$defaultWarehouse('p')} FROM motor_models mm JOIN parts p ON p.id = mm.finished_part_id WHERE mm.id = u.motor_model_id");
        DB::statement('ALTER TABLE motor_units ALTER COLUMN warehouse_id SET NOT NULL');

        // Alış satırında giriş deposu (kesimde oluşan stok girişi için; boşsa ürünün varsayılan deposu).
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->index()->constrained()->restrictOnDelete();
        });

        // Mamulde transfer yalnızca motor seçimiyle (motor başına, referansı motor kaydı).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_validate_insert() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                original stock_movements%ROWTYPE;
                unit_allows_decimal boolean;
                part_type text;
            BEGIN
                IF NEW.type = 'correction' THEN
                    SELECT * INTO original FROM stock_movements WHERE id = NEW.corrected_movement_id;

                    IF original.type = 'correction' THEN
                        RAISE EXCEPTION 'Ters kaydın kendisi ters kaydedilemez.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF original.type = 'transfer' THEN
                        RAISE EXCEPTION 'Transfer ters kaydedilemez; geri transfer yapın.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF original.part_id <> NEW.part_id OR original.warehouse_id <> NEW.warehouse_id OR NEW.quantity <> -original.quantity THEN
                        RAISE EXCEPTION 'Ters kayıt, orijinal hareketle aynı üründe, aynı depoda ve tam ters miktarda olmalıdır.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                SELECT u.allows_decimal, p.type INTO unit_allows_decimal, part_type
                FROM parts p JOIN units u ON u.id = p.unit_id
                WHERE p.id = NEW.part_id
                -- Birimin küsurat ayarı bu hareket commit edilene kadar değiştirilemesin (UnitService FOR UPDATE alır).
                FOR SHARE OF u;

                IF NOT unit_allows_decimal AND NEW.quantity <> trunc(NEW.quantity) THEN
                    RAISE EXCEPTION 'Bu ürünün birimi küsuratlı miktar kabul etmez.' USING ERRCODE = 'check_violation';
                END IF;

                -- Mamul kartında elle hareket yok (CLAUDE.md bölüm 5 "Mamul ve yarı mamul"); transfer motor seçimiyle.
                IF part_type = 'finished_good' AND NOT (
                    NEW.type IN ('production_output', 'shipment', 'correction')
                    OR (NEW.type = 'transfer' AND COALESCE(NEW.reference_type = 'motor_unit', false) AND abs(NEW.quantity) = 1)
                ) THEN
                    RAISE EXCEPTION 'Mamul kartında elle stok hareketi yapılamaz; mamul stoğu yalnızca üretim, sevk ve motor transferiyle değişir.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Eksi bakiye yalnızca eksiye düşebilen depoda ve mamul dışındaki üründe.
                IF NEW.balance_after < 0 AND NOT COALESCE(stock_balance_negative_allowed(NEW.part_id, NEW.warehouse_id), false) THEN
                    RAISE EXCEPTION 'Bu depoda stok eksiye düşemez.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Üretim depoları geçişi geri alınamaz.');
    }
};
