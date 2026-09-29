<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Aynı ad index'i, Customer::scopeActiveWithSameName'deki ifadeyle aynı olmalı. Türkçe noktasız ı, I'nın
 * küçüğüdür; lower('IŞIK') = 'işik' iken 'ışık' değişmez. translate ile ikisi de 'işik' olur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_normalized_name_index');
        DB::statement("CREATE INDEX customers_normalized_name_index ON customers (translate(lower(btrim(name)), 'ı', 'i'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_normalized_name_index');
        DB::statement('CREATE INDEX customers_normalized_name_index ON customers (lower(btrim(name)))');
    }
};
