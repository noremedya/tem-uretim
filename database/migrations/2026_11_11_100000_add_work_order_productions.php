<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kısım kısım üretim (karar 2026-11-05): üretimdeki iş emrinde motorlar/ürün ürettikçe "üretim kaydı" girilir; her
 * kayıt o kadar malzeme ve fire düşer, ürünü stoğa alır ve motorlara o günün tarihi ve seri numarasını verir. Üretilen
 * toplam iş emri adedine ulaşınca iş emri tamamlanır. Geri alma son kaydı geri alır.
 *
 * - work_orders.produced_quantity: geri alınmamış kayıtların toplamı (0 ≤ üretilen ≤ adet).
 * - work_order_productions: kayıt; değiştirilemez, silinemez (yalnızca bir kez geri alındı işaretlenir).
 * - work_order_production_movements: kaydın stok hareketleri (geri almada ters kaydedilir).
 * - motor_units.work_order_production_id: motorun üretildiği kayıt.
 *
 * Mevcut tamamlanmış iş emirleri tek kayıtla aktarılır (tarih: tamamlanma; kullanıcı: tamamlayan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->decimal('produced_quantity', 15, 3)->default(0);
        });

        Schema::create('work_order_productions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->index();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
        });

        Schema::create('work_order_production_movements', function (Blueprint $table): void {
            $table->foreignId('work_order_production_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->primary(['work_order_production_id', 'stock_movement_id']);
        });

        Schema::table('motor_units', function (Blueprint $table): void {
            $table->foreignId('work_order_production_id')->nullable()->index()->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE work_orders
                ADD CONSTRAINT work_orders_produced_quantity_check CHECK (produced_quantity >= 0 AND produced_quantity <= quantity);

            ALTER TABLE work_order_productions
                ADD CONSTRAINT work_order_productions_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT work_order_productions_reversal_check CHECK (
                    (reversed_at IS NULL AND reversed_by_user_id IS NULL AND reversal_reason IS NULL)
                    OR (reversed_at IS NOT NULL AND reversed_by_user_id IS NOT NULL AND btrim(reversal_reason) <> ''));

            -- Üretim kaydı silinmez; yalnızca bir kez geri alındı olarak işaretlenir.
            CREATE OR REPLACE FUNCTION work_order_productions_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'Üretim kaydı silinemez.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reversed_at IS NOT NULL
                    OR NEW.work_order_id IS DISTINCT FROM OLD.work_order_id
                    OR NEW.quantity IS DISTINCT FROM OLD.quantity
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Üretim kaydı değiştirilemez; yalnızca bir kez geri alınabilir.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER work_order_productions_immutable BEFORE UPDATE OR DELETE ON work_order_productions
                FOR EACH ROW EXECUTE FUNCTION work_order_productions_immutable();

            CREATE OR REPLACE FUNCTION work_order_production_movements_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Üretim kaydının hareket bağı değiştirilemez.' USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER work_order_production_movements_immutable BEFORE UPDATE OR DELETE ON work_order_production_movements
                FOR EACH ROW EXECUTE FUNCTION work_order_production_movements_immutable();

            CREATE TRIGGER work_order_productions_no_truncate BEFORE TRUNCATE ON work_order_productions
                FOR EACH STATEMENT EXECUTE FUNCTION work_order_productions_immutable();
            CREATE TRIGGER work_order_production_movements_no_truncate BEFORE TRUNCATE ON work_order_production_movements
                FOR EACH STATEMENT EXECUTE FUNCTION work_order_production_movements_immutable();

            -- Mevcut tamamlanmış iş emirleri: tek kayıt.
            UPDATE work_orders SET produced_quantity = quantity WHERE status = 'completed';

            INSERT INTO work_order_productions (work_order_id, quantity, user_id, created_at)
            SELECT w.id, w.quantity,
                   COALESCE(
                       (SELECT h.user_id FROM work_order_status_histories h
                         WHERE h.work_order_id = w.id AND h.to_status = 'completed' ORDER BY h.id DESC LIMIT 1),
                       (SELECT h.user_id FROM work_order_status_histories h WHERE h.work_order_id = w.id ORDER BY h.id LIMIT 1)),
                   COALESCE(w.completed_at, w.updated_at)
              FROM work_orders w
             WHERE w.status = 'completed';

            INSERT INTO work_order_production_movements (work_order_production_id, stock_movement_id)
            SELECT p.id, m.id
              FROM work_order_productions p
              JOIN stock_movements m ON m.reference_type = 'work_order' AND m.reference_id = p.work_order_id
             WHERE m.type IN ('production_consumption', 'production_scrap', 'production_output')
               AND NOT EXISTS (SELECT 1 FROM stock_movements c WHERE c.corrected_movement_id = m.id);

            UPDATE motor_units u SET work_order_production_id = p.id
              FROM work_order_productions p
             WHERE u.work_order_id = p.work_order_id AND u.status <> 'voided';
        SQL);
    }

    public function down(): void
    {
        Schema::table('motor_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_order_production_id');
        });
        Schema::dropIfExists('work_order_production_movements');
        Schema::dropIfExists('work_order_productions');
        DB::unprepared('DROP FUNCTION IF EXISTS work_order_productions_immutable() CASCADE; DROP FUNCTION IF EXISTS work_order_production_movements_immutable() CASCADE;');
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn('produced_quantity');
        });
    }
};
