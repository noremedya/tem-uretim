<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Düzeltme: invoice_links_unique() iki tabloda ortak kullanılır; PL/pgSQL CASE içinde diğer tablonun kolonuna
 * (NEW.motor_unit_id / NEW.stock_movement_id) başvurulamadığı için stok girişi bağlarken hata veriyordu.
 * Kolon değeri artık kayıttan genel olarak okunur (to_jsonb).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createFunction('(to_jsonb(NEW) ->> link_column)::bigint');
    }

    public function down(): void
    {
        $this->createFunction("CASE link_column WHEN 'motor_unit_id' THEN NEW.motor_unit_id ELSE NEW.stock_movement_id END");
    }

    private function createFunction(string $linkedIdExpression): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION invoice_links_unique() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            DECLARE
                link_column text := TG_ARGV[0];
                linked_id bigint;
                taken boolean;
            BEGIN
                linked_id := {$linkedIdExpression};

                -- Aynı kaydı bağlayan eşzamanlı işlemler sıraya girsin.
                PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_NAME), linked_id::int);

                EXECUTE format(
                    'SELECT EXISTS (SELECT 1 FROM %I l JOIN invoice_lines il ON il.id = l.invoice_line_id
                        JOIN invoices i ON i.id = il.invoice_id
                        WHERE l.%I = \$1 AND l.id <> \$2 AND i.status <> ''cancelled'')',
                    TG_TABLE_NAME, link_column
                ) INTO taken USING linked_id, NEW.id;

                IF taken THEN
                    RAISE EXCEPTION 'Bu kayıt iptal edilmemiş başka bir faturaya zaten bağlı.' USING ERRCODE = 'unique_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$;
        SQL);
    }
};
