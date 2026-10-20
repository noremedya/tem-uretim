<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama B1 — Depolar: depo bazlı bakiye ve kritik seviye, hareketlerde depo, depolar arası transfer,
 * depo bazlı negatif stok (mamul hariç).
 *
 * Mevcut bakiyeler ve hareketler ürün türüne göre varsayılan depolara yerleştirilir. Hareketler değiştirilemez:
 * değişmezlik trigger'ı yalnızca bu geçiş için, boş depo alanının bir kez doldurulmasına izin veren hâle alınır;
 * alan NOT NULL olduktan sonra katı hâline geri döner.
 */
return new class extends Migration
{
    /** Varsayılan depolar ve hangi ürün türlerinin varsayılanı oldukları. */
    private const WAREHOUSES = [
        ['code' => 'HAM', 'name' => 'Hammadde deposu', 'types' => ['raw_material', 'component', 'direct_consumable', 'indirect_consumable'], 'production' => false, 'shipping' => false],
        ['code' => 'URT', 'name' => 'Üretim deposu', 'types' => [], 'production' => true, 'shipping' => false],
        ['code' => 'YM', 'name' => 'Yarı mamul deposu', 'types' => ['semi_finished'], 'production' => false, 'shipping' => false],
        ['code' => 'MML', 'name' => 'Mamul / sevkiyat deposu', 'types' => ['finished_good'], 'production' => false, 'shipping' => true],
    ];

    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('allows_negative')->default(false);
            $table->boolean('is_production')->default(false);
            $table->boolean('is_shipping')->default(false);
            $table->jsonb('default_part_types')->default('[]');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warehouses
                ADD CONSTRAINT warehouses_code_check CHECK (btrim(code) <> ''),
                ADD CONSTRAINT warehouses_name_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT warehouses_types_check CHECK (jsonb_typeof(default_part_types) = 'array');
            -- Tek üretim deposu ve tek sevkiyat deposu.
            CREATE UNIQUE INDEX warehouses_one_production ON warehouses (is_production) WHERE is_production;
            CREATE UNIQUE INDEX warehouses_one_shipping ON warehouses (is_shipping) WHERE is_shipping;
            SQL);

        foreach (self::WAREHOUSES as $w) {
            DB::table('warehouses')->insert([
                'code' => $w['code'], 'name' => $w['name'], 'default_part_types' => json_encode($w['types']),
                'is_production' => $w['production'], 'is_shipping' => $w['shipping'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Türün varsayılan deposu (geçiş için SQL ifadesi).
        $warehouseForType = '(SELECT w.id FROM warehouses w WHERE w.default_part_types @> to_jsonb(%s::text) LIMIT 1)';

        Schema::table('parts', function (Blueprint $table): void {
            $table->foreignId('default_warehouse_id')->nullable()->index()->constrained('warehouses')->restrictOnDelete();
        });

        // --- Bakiyeler: ürün × depo, kritik seviye depo bazlı ---
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->decimal('critical_level', 15, 3)->default(0);
        });

        DB::statement('UPDATE stock_balances b SET warehouse_id = '.sprintf($warehouseForType, 'p.type').', critical_level = p.critical_level FROM parts p WHERE p.id = b.part_id');

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_balances ALTER COLUMN warehouse_id SET NOT NULL;
            ALTER TABLE stock_balances DROP CONSTRAINT stock_balances_part_id_unique;
            ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_part_warehouse_unique UNIQUE (part_id, warehouse_id);
            ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_critical_level_check CHECK (critical_level >= 0);
            -- Bakiye >= 0 kuralı depo ayarına bağlanır (trigger): eksiye düşebilen depoda ve mamul dışındaki üründe eksi olabilir.
            ALTER TABLE stock_balances DROP CONSTRAINT stock_balances_quantity_check;

            CREATE OR REPLACE FUNCTION stock_balance_negative_allowed(p_part_id bigint, p_warehouse_id bigint) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT w.allows_negative AND p.type <> 'finished_good'
                FROM warehouses w, parts p WHERE w.id = p_warehouse_id AND p.id = p_part_id
            $$;

            CREATE OR REPLACE FUNCTION stock_balances_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.quantity < 0 AND NOT COALESCE(stock_balance_negative_allowed(NEW.part_id, NEW.warehouse_id), false) THEN
                    RAISE EXCEPTION 'Bu depoda stok eksiye düşemez.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_balances_validate BEFORE INSERT OR UPDATE ON stock_balances
                FOR EACH ROW EXECUTE FUNCTION stock_balances_validate();
            SQL);

        // Kritik seviye artık depo bazlı (bakiye satırında).
        DB::unprepared('ALTER TABLE parts DROP CONSTRAINT IF EXISTS parts_critical_level_check');
        Schema::table('parts', fn (Blueprint $table) => $table->dropColumn('critical_level'));

        // --- Hareketler: depo ve transfer ---
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->uuid('transfer_id')->nullable()->index();
        });

        // Geçiş: değişmezlik trigger'ı yalnızca boş depo alanının doldurulmasına izin verir (diğer her şey aynı kalmalı).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.warehouse_id IS NULL AND NEW.warehouse_id IS NOT NULL
                    AND (to_jsonb(NEW) - 'warehouse_id') = (to_jsonb(OLD) - 'warehouse_id') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Stok hareketleri değiştirilemez veya silinemez (%). Düzeltme için ters kayıt kullanın.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);

        DB::statement('UPDATE stock_movements m SET warehouse_id = '.sprintf($warehouseForType, 'p.type').' FROM parts p WHERE p.id = m.part_id');

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_movements ALTER COLUMN warehouse_id SET NOT NULL;

            -- Katı hâline geri dön.
            CREATE OR REPLACE FUNCTION stock_movements_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Stok hareketleri değiştirilemez veya silinemez (%). Düzeltme için ters kayıt kullanın.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_balance_check;
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_balance_check CHECK (balance_after = balance_before + quantity);

            ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check;
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN (
                'stock_in', 'stock_out', 'production_consumption', 'production_scrap', 'production_output', 'shipment',
                'count_adjustment', 'return', 'correction', 'transfer'));

            ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_sign_check;
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_sign_check CHECK (
                (type IN ('stock_in', 'return', 'production_output') AND quantity > 0)
                OR (type IN ('stock_out', 'production_consumption', 'production_scrap', 'shipment') AND quantity < 0)
                OR type IN ('count_adjustment', 'correction', 'transfer'));

            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_transfer_check CHECK ((type = 'transfer') = (transfer_id IS NOT NULL));

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

                -- Mamul kartında elle hareket yok (CLAUDE.md bölüm 5 "Mamul ve yarı mamul").
                IF part_type = 'finished_good' AND NEW.type NOT IN ('production_output', 'shipment', 'correction') THEN
                    RAISE EXCEPTION 'Mamul kartında elle stok hareketi yapılamaz; mamul stoğu yalnızca üretim ve sevkle değişir.'
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
        throw new RuntimeException('Depo geçişi geri alınamaz (hareketlere depo yazıldı, kritik seviyeler depolara taşındı).');
    }
};
