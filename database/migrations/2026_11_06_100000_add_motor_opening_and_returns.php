<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Açılış motoru ve motor iadesi (CLAUDE.md bölüm 5 "Açılış motoru ve iade"): iş emri olmayan motor, iade kayıtları,
 * iade deposu, mamulde motor başına giriş ve iade hareketi, aynı motorun iade faturasından sonra yeniden faturalanması.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE motor_units ALTER COLUMN work_order_id DROP NOT NULL');

        Schema::create('motor_unit_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('motor_unit_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('shipped_at');
            $table->foreignId('shipped_by_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable()->index();
        });

        DB::statement("ALTER TABLE motor_unit_returns ADD CONSTRAINT motor_unit_returns_description_check CHECK (btrim(description) <> '')");

        DB::table('warehouses')->insertOrIgnore([
            'code' => 'IADE', 'name' => 'İade / arızalı deposu', 'default_part_types' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION motor_unit_returns_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Motor iade kayıtları değiştirilemez veya silinemez (%).', TG_OP USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER motor_unit_returns_immutable BEFORE UPDATE OR DELETE ON motor_unit_returns
                FOR EACH ROW EXECUTE FUNCTION motor_unit_returns_immutable();
            CREATE TRIGGER motor_unit_returns_no_truncate BEFORE TRUNCATE ON motor_unit_returns
                FOR EACH STATEMENT EXECUTE FUNCTION motor_unit_returns_immutable();

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
                    OR (NEW.type IN ('stock_in', 'return') AND COALESCE(NEW.reference_type = 'motor_unit', false) AND NEW.quantity = 1)
                ) THEN
                    RAISE EXCEPTION 'Mamul kartında elle stok hareketi yapılamaz; mamul stoğu yalnızca üretim, sevk, motor transferi, açılış motoru ve motor iadesiyle değişir.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Eksi bakiye yalnızca eksiye düşebilen depoda ve mamul dışındaki üründe.
                IF NEW.balance_after < 0 AND NOT COALESCE(stock_balance_negative_allowed(NEW.part_id, NEW.warehouse_id), false) THEN
                    RAISE EXCEPTION 'Bu depoda stok eksiye düşemez.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            -- Motor bir satış faturasında durur; kesilmiş iade faturası motoru yeniden faturalanabilir yapar. İade faturası
            -- yalnızca kesilmiş satışı olan ve henüz iadesi olmayan motora bağlanır.
            CREATE OR REPLACE FUNCTION invoice_line_motor_valid() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                link_kind text;
                sales integer;
                issued_sales integer;
                returns integer;
                issued_returns integer;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_NAME), NEW.motor_unit_id::int);

                SELECT i.kind INTO link_kind FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE il.id = NEW.invoice_line_id;

                SELECT count(*) FILTER (WHERE i.kind = 'normal'),
                       count(*) FILTER (WHERE i.kind = 'normal' AND i.status = 'issued'),
                       count(*) FILTER (WHERE i.kind = 'return'),
                       count(*) FILTER (WHERE i.kind = 'return' AND i.status = 'issued')
                INTO sales, issued_sales, returns, issued_returns
                FROM invoice_line_motor_units l
                JOIN invoice_lines il ON il.id = l.invoice_line_id
                JOIN invoices i ON i.id = il.invoice_id
                WHERE l.motor_unit_id = NEW.motor_unit_id AND l.id <> NEW.id AND i.status <> 'cancelled';

                IF (link_kind = 'normal' AND sales > issued_returns) OR (link_kind = 'return' AND returns >= issued_sales) THEN
                    RAISE EXCEPTION 'Bu kayıt iptal edilmemiş başka bir faturaya zaten bağlı.' USING ERRCODE = 'unique_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER invoice_line_motor_units_unique ON invoice_line_motor_units;
            CREATE TRIGGER invoice_line_motor_units_unique
                BEFORE INSERT OR UPDATE ON invoice_line_motor_units
                FOR EACH ROW EXECUTE FUNCTION invoice_line_motor_valid();
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Açılış motoru ve iade geçişi geri alınamaz.');
    }
};
