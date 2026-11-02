<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün deposu (CLAUDE.md bölüm 5 "Stok"): reçetedeki her ürünün kendi deposu. Depo kodu ürün kodundan gelir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE warehouses ALTER COLUMN code TYPE varchar(100)');

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->foreignId('part_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Ürün deposu geçişi geri alınamaz.');
    }
};
