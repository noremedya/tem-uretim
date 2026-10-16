<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 4c (CLAUDE.md bölüm 4–5): üretilen motorlar. Silinmez; seri no tekrar kullanılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_units', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->foreignId('motor_model_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('production_batch_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->date('production_date')->index();
            $table->string('status', 20)->index();
            $table->timestamp('shipped_at')->nullable()->index();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE motor_units
                ADD CONSTRAINT motor_units_serial_not_blank_check CHECK (btrim(serial_number) <> ''),
                ADD CONSTRAINT motor_units_status_check CHECK (status IN ('in_stock', 'shipped', 'voided')),
                ADD CONSTRAINT motor_units_shipment_check CHECK (
                    (status = 'shipped') = (shipped_at IS NOT NULL AND shipped_by_user_id IS NOT NULL)
                )
        SQL);

        // Üretilen motor kayıtları silinemez (CLAUDE.md bölüm 3 kural 7).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION motor_units_no_delete() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Üretilen motor kayıtları silinemez (%).', TG_OP USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER motor_units_no_delete
                BEFORE DELETE ON motor_units
                FOR EACH ROW EXECUTE FUNCTION motor_units_no_delete();

            CREATE TRIGGER motor_units_no_truncate
                BEFORE TRUNCATE ON motor_units
                FOR EACH STATEMENT EXECUTE FUNCTION motor_units_no_delete();

            -- Seri no ve üretim bağlantısı sonradan değişmez.
            CREATE OR REPLACE FUNCTION motor_units_identity_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Motorun seri numarası, modeli ve iş emri değiştirilemez.' USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER motor_units_identity_immutable
                BEFORE UPDATE OF serial_number, motor_model_id, work_order_id, production_date ON motor_units
                FOR EACH ROW
                WHEN (OLD.serial_number IS DISTINCT FROM NEW.serial_number
                    OR OLD.motor_model_id IS DISTINCT FROM NEW.motor_model_id
                    OR OLD.work_order_id IS DISTINCT FROM NEW.work_order_id
                    OR OLD.production_date IS DISTINCT FROM NEW.production_date)
                EXECUTE FUNCTION motor_units_identity_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_units');
        DB::unprepared('DROP FUNCTION IF EXISTS motor_units_no_delete(); DROP FUNCTION IF EXISTS motor_units_identity_immutable();');
    }
};
