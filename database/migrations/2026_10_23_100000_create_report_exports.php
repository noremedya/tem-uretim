<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 8: kuyrukta hazırlanan rapor dosyaları (yalnızca hazırlayan indirir; 7 gün sonra silinir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('report_key', 50);
            $table->json('filters');
            $table->string('file_name');
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('status', 10)->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'ready', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
