<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            // false: bu birimdeki miktarlar tam sayı olmalı (ör. adet).
            $table->boolean('allows_decimal')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE units ADD CONSTRAINT units_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_lock_version_check CHECK (lock_version >= 0)');

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->string('barcode', 100)->nullable()->unique();
            // 0: kritik stok takibi yok.
            $table->decimal('critical_level', 15, 3)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE parts ADD CONSTRAINT parts_type_check CHECK (type IN ('raw_material', 'component', 'consumable'))");
        DB::statement("ALTER TABLE parts ADD CONSTRAINT parts_code_not_blank_check CHECK (btrim(code) <> '')");
        DB::statement("ALTER TABLE parts ADD CONSTRAINT parts_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE parts ADD CONSTRAINT parts_barcode_not_blank_check CHECK (barcode IS NULL OR btrim(barcode) <> '')");
        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_critical_level_check CHECK (critical_level >= 0)');
        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_lock_version_check CHECK (lock_version >= 0)');

        // Tek yazıcısı StockService. Parça oluşturulurken 0 bakiyeyle açılır; böylece kilitlenecek satır hep vardır.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            // Kritik stok bildirimi gönderildi mi? Bakiye kritik seviyenin üstüne çıkınca false olur.
            $table->boolean('is_below_critical')->default(false);
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantity_check CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('units');
    }
};
