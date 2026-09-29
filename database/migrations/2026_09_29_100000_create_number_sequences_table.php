<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Numara sayaçları (NumberSequenceService). scope: şablonun sıra dışındaki kısmı (ör. "SIP-2026-#").
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('scope', 150);
            $table->unsignedBigInteger('last_value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });

        DB::statement('ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_last_value_check CHECK (last_value > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
