<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 5e — İhracat (CLAUDE.md bölüm 5 "İhracat"): carinin ülkesi ve yabancı vergi no, motor modelinin GTİP'i ve
 * net ağırlığı, faturada ihracat türü, teslim/gönderim/kap bilgisi, tecil edilen KDV ve gümrük beyannamesi.
 * Kesilmiş fatura kilidine yeni kolonlar eklenir; gümrük beyannamesi kesildikten sonra da girilebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->char('country_code', 2)->default('TR')->index();
            $table->string('foreign_tax_id', 50)->nullable();
        });

        Schema::table('motor_models', function (Blueprint $table): void {
            $table->string('gtip_code', 12)->nullable();
            $table->decimal('net_weight_kg', 8, 3)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('export_type', 20)->nullable()->index();
            $table->decimal('deferred_vat_total', 14, 2)->default(0);
            $table->string('incoterm', 3)->nullable();
            $table->string('transport_mode', 20)->nullable();
            $table->unsignedInteger('package_count')->nullable();
            $table->string('package_type', 20)->nullable();
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->decimal('net_weight_kg', 10, 3)->nullable();
            $table->string('customs_declaration_number', 30)->nullable();
            $table->date('customs_declaration_date')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('gtip_code', 12)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_country_code_check CHECK (country_code ~ '^[A-Z]{2}$'),
                -- Türk VKN/TCKN ve vergi dairesi yalnızca Türkiye'deki caride; yabancı vergi no yalnızca yurt dışında.
                ADD CONSTRAINT customers_domestic_tax_check CHECK (country_code = 'TR' OR (tax_number IS NULL AND tax_office IS NULL)),
                ADD CONSTRAINT customers_foreign_tax_check CHECK (country_code <> 'TR' OR foreign_tax_id IS NULL);

            ALTER TABLE motor_models
                ADD CONSTRAINT motor_models_gtip_check CHECK (gtip_code ~ '^[0-9]{12}$'),
                ADD CONSTRAINT motor_models_net_weight_check CHECK (net_weight_kg > 0);

            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_gtip_check CHECK (gtip_code ~ '^[0-9]{12}$');

            ALTER TABLE invoices
                ADD CONSTRAINT invoices_export_type_check CHECK (export_type IN ('export', 'registered_export')),
                ADD CONSTRAINT invoices_export_sales_only CHECK (export_type IS NULL OR direction = 'sales'),
                ADD CONSTRAINT invoices_export_vat_check CHECK (export_type IS DISTINCT FROM 'export' OR vat_total = 0),
                ADD CONSTRAINT invoices_deferred_vat_check CHECK (deferred_vat_total >= 0 AND (deferred_vat_total = 0 OR export_type = 'registered_export')),
                ADD CONSTRAINT invoices_incoterm_check CHECK (incoterm IN ('EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP')),
                ADD CONSTRAINT invoices_transport_mode_check CHECK (transport_mode IN ('sea', 'rail', 'road', 'air', 'post', 'multimodal')),
                ADD CONSTRAINT invoices_package_type_check CHECK (package_type IN ('pallet', 'carton', 'crate', 'box')),
                ADD CONSTRAINT invoices_package_count_check CHECK (package_count > 0),
                ADD CONSTRAINT invoices_weights_check CHECK ((gross_weight_kg IS NULL OR gross_weight_kg > 0) AND (net_weight_kg IS NULL OR net_weight_kg > 0) AND (gross_weight_kg IS NULL OR net_weight_kg IS NULL OR net_weight_kg <= gross_weight_kg)),
                ADD CONSTRAINT invoices_customs_pair_check CHECK ((customs_declaration_number IS NULL) = (customs_declaration_date IS NULL)),
                ADD CONSTRAINT invoices_customs_export_only CHECK (customs_declaration_number IS NULL OR export_type IS NOT NULL);

            -- Kesilmiş fatura kilidi: yeni ihracat kolonları da kilitlenir; gümrük beyannamesi (no, tarih) kesildikten
            -- sonra girilebilir (iptal edilmiş faturada hiçbir şey değişmez).
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
                        NEW.vat_total, NEW.grand_total, NEW.grand_total_try, NEW.issued_at, NEW.issued_by_user_id,
                        NEW.export_type, NEW.deferred_vat_total, NEW.incoterm, NEW.transport_mode, NEW.package_count,
                        NEW.package_type, NEW.gross_weight_kg, NEW.net_weight_kg)
                    IS DISTINCT FROM
                       (OLD.direction, OLD.kind, OLD.customer_id, OLD.invoice_number, OLD.counterparty_number, OLD.original_invoice_id,
                        OLD.invoice_date, OLD.due_date, OLD.currency, OLD.exchange_rate, OLD.subtotal, OLD.discount_total,
                        OLD.vat_total, OLD.grand_total, OLD.grand_total_try, OLD.issued_at, OLD.issued_by_user_id,
                        OLD.export_type, OLD.deferred_vat_total, OLD.incoterm, OLD.transport_mode, OLD.package_count,
                        OLD.package_type, OLD.gross_weight_kg, OLD.net_weight_kg)
                ) THEN
                    RAISE EXCEPTION 'Kesilmiş fatura değiştirilemez; düzeltme iade faturasıyla yapılır.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        // Kilit fonksiyonu önceki hâline (2026_10_21_100000) döner.
        DB::unprepared(<<<'SQL'
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
            SQL);

        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('gtip_code'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn([
            'export_type', 'deferred_vat_total', 'incoterm', 'transport_mode', 'package_count', 'package_type',
            'gross_weight_kg', 'net_weight_kg', 'customs_declaration_number', 'customs_declaration_date',
        ]));
        Schema::table('motor_models', fn (Blueprint $table) => $table->dropColumn(['gtip_code', 'net_weight_kg']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['country_code', 'foreign_tax_id']));
    }
};
