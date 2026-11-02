<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firmanın alüminyum parça listesi: flanş da alüminyum depoda; ayak ve hava perdesi için yarı mamul alt türü. Parça
 * kartları başlangıç dosyasındadır (App\Imports\StarterCatalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        $warehouse = DB::table('warehouses')->where('code', 'ALU')->value('id');

        foreach (['Ayak', 'Hava perdesi'] as $name) {
            DB::table('part_subtypes')->insertOrIgnore(['type' => 'semi_finished', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('part_subtypes')
            ->where('type', 'semi_finished')
            ->whereIn('name', ['Flanş', 'Ayak', 'Hava perdesi'])
            ->update(['default_warehouse_id' => $warehouse]);
    }

    public function down(): void
    {
        throw new RuntimeException('Alüminyum alt türleri geçişi geri alınamaz.');
    }
};
