<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Markalar (tanım listesi) ve ürüne (stok kartı) marka ve açıklama alanı. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        // Ad büyük/küçük harf (Türkçe I/ı dahil) ve baştaki/sondaki boşluk önemsiz biçimde tekildir.
        DB::statement("CREATE UNIQUE INDEX brands_name_normalized_unique ON brands (translate(lower(btrim(name)), 'ı', 'i'))");
        DB::statement("ALTER TABLE brands ADD CONSTRAINT brands_name_check CHECK (btrim(name) <> '')");

        Schema::table('parts', function (Blueprint $table): void {
            $table->foreignId('brand_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn('description');
        });

        Schema::dropIfExists('brands');
    }
};
