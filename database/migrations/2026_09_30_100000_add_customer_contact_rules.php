<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ALTER COLUMN phone SET NOT NULL,
                ADD CONSTRAINT customers_phone_not_blank_check CHECK (btrim(phone) <> ''),
                ADD CONSTRAINT customers_tax_office_not_blank_check CHECK (tax_office IS NULL OR btrim(tax_office) <> ''),
                -- Vergi no ve vergi dairesi birlikte girilir: biri doluysa diğeri de dolu olmalı.
                ADD CONSTRAINT customers_tax_pair_check CHECK ((tax_number IS NULL) = (tax_office IS NULL))
        SQL);

        // Aynı ad uyarısı (Customer::scopeActiveWithSameName) bu ifadeyle arar; unique değildir.
        // translate: Türkçe noktasız ı, I'nın küçüğüdür (lower('IŞIK') = 'işik', 'ışık' → 'işik').
        DB::statement("CREATE INDEX customers_normalized_name_index ON customers (translate(lower(btrim(name)), 'ı', 'i'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_normalized_name_index');

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                DROP CONSTRAINT IF EXISTS customers_tax_pair_check,
                DROP CONSTRAINT IF EXISTS customers_tax_office_not_blank_check,
                DROP CONSTRAINT IF EXISTS customers_phone_not_blank_check,
                ALTER COLUMN phone DROP NOT NULL
        SQL);
    }
};
