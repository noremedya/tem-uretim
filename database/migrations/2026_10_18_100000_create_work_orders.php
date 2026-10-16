<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 4b (CLAUDE.md bölüm 4–5): üretim partileri, iş emirleri, iş emri malzeme listesi, durum geçmişi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 50)->unique();
            $table->date('date')->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE production_batches
                ADD CONSTRAINT production_batches_number_not_blank_check CHECK (btrim(batch_number) <> ''),
                ADD CONSTRAINT production_batches_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_number', 50)->unique();
            $table->foreignId('product_part_id')->index()->constrained('parts')->restrictOnDelete();
            // Mamul iş emrinde dolu (ürün = modelin mamul kartı).
            $table->foreignId('motor_model_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('production_batch_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->foreignId('assigned_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_orders
                ADD CONSTRAINT work_orders_number_not_blank_check CHECK (btrim(work_order_number) <> ''),
                ADD CONSTRAINT work_orders_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT work_orders_status_check CHECK (status IN ('planned', 'in_progress', 'completed', 'cancelled')),
                ADD CONSTRAINT work_orders_planned_dates_check CHECK (planned_start IS NULL OR planned_end >= planned_start),
                ADD CONSTRAINT work_orders_cancellation_check CHECK (
                    status <> 'cancelled' OR (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
                ),
                ADD CONSTRAINT work_orders_order_item_check CHECK (order_item_id IS NULL OR motor_model_id IS NOT NULL),
                ADD CONSTRAINT work_orders_lock_version_check CHECK (lock_version >= 0)
        SQL);

        // Ürün kuralları (başka tablolara bakan kontroller).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION work_orders_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                product_type text;
            BEGIN
                SELECT type INTO product_type FROM parts WHERE id = NEW.product_part_id;

                IF product_type NOT IN ('semi_finished', 'finished_good') THEN
                    RAISE EXCEPTION 'İş emri yalnızca yarı mamul veya mamul üretir.' USING ERRCODE = 'check_violation';
                END IF;

                IF (product_type = 'finished_good') <> (NEW.motor_model_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'Mamul iş emri bir motor modeline bağlı olmalıdır (yarı mamulde model olmaz).' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.motor_model_id IS NOT NULL
                    AND (SELECT finished_part_id FROM motor_models WHERE id = NEW.motor_model_id) <> NEW.product_part_id THEN
                    RAISE EXCEPTION 'İş emrinin ürünü motor modelinin mamul kartı olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.order_item_id IS NOT NULL
                    AND (SELECT motor_model_id FROM order_items WHERE id = NEW.order_item_id) <> NEW.motor_model_id THEN
                    RAISE EXCEPTION 'Sipariş kaleminin motor modeli iş emrininkiyle aynı olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER work_orders_validate
                BEFORE INSERT OR UPDATE OF product_part_id, motor_model_id, order_item_id ON work_orders
                FOR EACH ROW EXECUTE FUNCTION work_orders_validate();
        SQL);

        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 3);
            $table->decimal('scrap_rate', 5, 2)->default(0);
            $table->decimal('required_quantity', 15, 3);
            $table->decimal('planned_scrap', 15, 3)->default(0);
            $table->decimal('actual_scrap', 15, 3)->nullable();
            $table->timestamps();

            // work_order_id için de index görevi görür.
            $table->unique(['work_order_id', 'part_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_order_materials
                ADD CONSTRAINT work_order_materials_quantity_check CHECK (quantity_per_unit > 0 AND required_quantity > 0),
                ADD CONSTRAINT work_order_materials_scrap_rate_check CHECK (scrap_rate >= 0 AND scrap_rate < 100),
                ADD CONSTRAINT work_order_materials_scrap_check CHECK (planned_scrap >= 0 AND (actual_scrap IS NULL OR actual_scrap >= 0))
        SQL);

        Schema::create('work_order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->index()->constrained()->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_order_status_histories
                ADD CONSTRAINT work_order_status_histories_status_check CHECK (
                    (from_status IS NULL OR from_status IN ('planned', 'in_progress', 'completed', 'cancelled'))
                    AND to_status IN ('planned', 'in_progress', 'completed', 'cancelled')
                )
        SQL);

        // Durum geçmişi değiştirilemez ve silinemez (CLAUDE.md bölüm 3 kural 7).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION work_order_status_histories_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'İş emri durum geçmişi değiştirilemez veya silinemez (%).', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER work_order_status_histories_no_update_delete
                BEFORE UPDATE OR DELETE ON work_order_status_histories
                FOR EACH ROW EXECUTE FUNCTION work_order_status_histories_immutable();

            CREATE TRIGGER work_order_status_histories_no_truncate
                BEFORE TRUNCATE ON work_order_status_histories
                FOR EACH STATEMENT EXECUTE FUNCTION work_order_status_histories_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_status_histories');
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('production_batches');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS work_order_status_histories_immutable();
            DROP FUNCTION IF EXISTS work_orders_validate();
        SQL);
    }
};
