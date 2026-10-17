<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 5b (CLAUDE.md bölüm 4–5 "Faturalar").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->index();
            $table->string('kind', 10)->index();
            $table->string('status', 10)->index();
            $table->foreignId('customer_id')->index()->constrained()->restrictOnDelete();
            $table->string('invoice_number', 50)->nullable()->unique();
            $table->string('counterparty_number', 50)->nullable();
            $table->foreignId('original_invoice_id')->nullable()->index()->constrained('invoices')->restrictOnDelete();
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('vat_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('grand_total_try', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'counterparty_number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
                ADD CONSTRAINT invoices_direction_check CHECK (direction IN ('sales', 'purchase')),
                ADD CONSTRAINT invoices_kind_check CHECK (kind IN ('normal', 'return')),
                ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'issued', 'cancelled')),
                ADD CONSTRAINT invoices_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT invoices_rate_check CHECK (exchange_rate > 0 AND (currency <> 'TRY' OR exchange_rate = 1)),
                ADD CONSTRAINT invoices_due_date_check CHECK (due_date >= invoice_date),
                ADD CONSTRAINT invoices_totals_check CHECK (subtotal >= 0 AND discount_total >= 0 AND vat_total >= 0 AND grand_total >= 0),
                -- Bizim kestiğimiz belge (satış faturası, alış iadesi) kesilmişse numarası vardır; karşı tarafınkinde
                -- karşı tarafın numarası vardır.
                ADD CONSTRAINT invoices_number_check CHECK (
                    status = 'draft'
                    OR ((direction = 'sales') = (kind = 'normal') AND invoice_number IS NOT NULL)
                    OR ((direction = 'sales') <> (kind = 'normal') AND counterparty_number IS NOT NULL)
                ),
                ADD CONSTRAINT invoices_issued_check CHECK (
                    (status = 'draft') = (issued_at IS NULL) AND (status = 'draft' OR grand_total_try IS NOT NULL)
                ),
                ADD CONSTRAINT invoices_cancellation_check CHECK (
                    (status = 'cancelled') = (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
                ),
                ADD CONSTRAINT invoices_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('motor_model_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 50);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('line_gross', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_net', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_description_check CHECK (btrim(description) <> ''),
                ADD CONSTRAINT invoice_lines_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT invoice_lines_price_check CHECK (unit_price >= 0),
                ADD CONSTRAINT invoice_lines_rates_check CHECK (discount_rate BETWEEN 0 AND 100 AND vat_rate BETWEEN 0 AND 100),
                ADD CONSTRAINT invoice_lines_item_check CHECK (part_id IS NULL OR motor_model_id IS NULL)
        SQL);

        Schema::create('invoice_line_motor_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('motor_unit_id')->index()->constrained()->restrictOnDelete();
            $table->unique(['invoice_line_id', 'motor_unit_id']);
        });

        Schema::create('invoice_line_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->index()->constrained()->restrictOnDelete();
            $table->unique(['invoice_line_id', 'stock_movement_id']);
        });

        // Bir motor / stok girişi iptal edilmemiş tek bir faturaya bağlanır (iptal edilen fatura bağı serbest bırakır).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_links_unique() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                link_column text := TG_ARGV[0];
                linked_id bigint;
                taken boolean;
            BEGIN
                linked_id := CASE link_column WHEN 'motor_unit_id' THEN NEW.motor_unit_id ELSE NEW.stock_movement_id END;

                -- Aynı kaydı bağlayan eşzamanlı işlemler sıraya girsin.
                PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_NAME), linked_id::int);

                EXECUTE format(
                    'SELECT EXISTS (SELECT 1 FROM %I l JOIN invoice_lines il ON il.id = l.invoice_line_id
                        JOIN invoices i ON i.id = il.invoice_id
                        WHERE l.%I = $1 AND l.id <> $2 AND i.status <> ''cancelled'')',
                    TG_TABLE_NAME, link_column
                ) INTO taken USING linked_id, NEW.id;

                IF taken THEN
                    RAISE EXCEPTION 'Bu kayıt iptal edilmemiş başka bir faturaya zaten bağlı.' USING ERRCODE = 'unique_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER invoice_line_motor_units_unique
                BEFORE INSERT OR UPDATE ON invoice_line_motor_units
                FOR EACH ROW EXECUTE FUNCTION invoice_links_unique('motor_unit_id');

            CREATE TRIGGER invoice_line_stock_movements_unique
                BEFORE INSERT OR UPDATE ON invoice_line_stock_movements
                FOR EACH ROW EXECUTE FUNCTION invoice_links_unique('stock_movement_id');
        SQL);

        // Kesilmiş veya iptal edilmiş fatura silinmez (taslak silinebilir).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_no_delete_issued() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'Kesilmiş fatura silinemez; iptal edin.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER invoices_no_delete_issued
                BEFORE DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION invoices_no_delete_issued();

            -- Kesilmiş fatura kilitlidir: yalnızca iptal (durum, iptal zamanı, açıklama) değişebilir.
            CREATE OR REPLACE FUNCTION invoices_locked_after_issue() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'İptal edilmiş fatura değiştirilemez.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'issued' AND (
                    NEW.status NOT IN ('issued', 'cancelled')
                    OR (NEW.direction, NEW.kind, NEW.customer_id, NEW.invoice_number, NEW.counterparty_number, NEW.original_invoice_id,
                        NEW.invoice_date, NEW.due_date, NEW.currency, NEW.exchange_rate, NEW.subtotal, NEW.discount_total,
                        NEW.vat_total, NEW.grand_total, NEW.grand_total_try, NEW.issued_at, NEW.issued_by_user_id)
                    IS DISTINCT FROM
                       (OLD.direction, OLD.kind, OLD.customer_id, OLD.invoice_number, OLD.counterparty_number, OLD.original_invoice_id,
                        OLD.invoice_date, OLD.due_date, OLD.currency, OLD.exchange_rate, OLD.subtotal, OLD.discount_total,
                        OLD.vat_total, OLD.grand_total, OLD.grand_total_try, OLD.issued_at, OLD.issued_by_user_id)
                ) THEN
                    RAISE EXCEPTION 'Kesilmiş fatura değiştirilemez; düzeltme iade faturasıyla yapılır.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER invoices_locked_after_issue
                BEFORE UPDATE ON invoices
                FOR EACH ROW EXECUTE FUNCTION invoices_locked_after_issue();

            -- Taslak olmayan faturanın satırları ve bağlantıları değiştirilemez, eklenemez, silinemez.
            CREATE OR REPLACE FUNCTION invoice_children_locked() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                line_id bigint;
                invoice_status text;
            BEGIN
                IF TG_TABLE_NAME = 'invoice_lines' THEN
                    SELECT status INTO invoice_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
                ELSE
                    line_id := CASE TG_OP WHEN 'DELETE' THEN OLD.invoice_line_id ELSE NEW.invoice_line_id END;
                    SELECT i.status INTO invoice_status FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE il.id = line_id;
                END IF;

                -- Taslak silinirken (invoices satırı silinmeden önce) çocuklar silinebilir; kesilmiş/iptal edilmiş faturada asla.
                IF invoice_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Kesilmiş faturanın satırları değiştirilemez.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;

            CREATE TRIGGER invoice_lines_locked
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_lines
                FOR EACH ROW EXECUTE FUNCTION invoice_children_locked();

            CREATE TRIGGER invoice_line_motor_units_locked
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_line_motor_units
                FOR EACH ROW EXECUTE FUNCTION invoice_children_locked();

            CREATE TRIGGER invoice_line_stock_movements_locked
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_line_stock_movements
                FOR EACH ROW EXECUTE FUNCTION invoice_children_locked();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_stock_movements');
        Schema::dropIfExists('invoice_line_motor_units');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_links_unique(); DROP FUNCTION IF EXISTS invoices_no_delete_issued(); DROP FUNCTION IF EXISTS invoices_locked_after_issue(); DROP FUNCTION IF EXISTS invoice_children_locked();');
    }
};
