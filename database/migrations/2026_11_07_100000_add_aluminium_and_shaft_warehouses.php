<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alüminyum parçalar ve miller için ayrı depo (raf bazlı kayıt depoları). Alt türe varsayılan depo eklenir: alt türün
 * ürünleri, kartta depo seçilmemişse bu depoyu kullanır. Mevcut bakiyeler taşınmaz.
 */
return new class extends Migration
{
    private const WAREHOUSES = [
        ['code' => 'ALU', 'name' => 'Alüminyum parçalar deposu', 'subtypes' => ['Gövde', 'Kapak', 'Enjekte rotor']],
        ['code' => 'MIL', 'name' => 'Mil deposu', 'subtypes' => ['İşlenmiş mil']],
    ];

    public function up(): void
    {
        Schema::table('part_subtypes', function (Blueprint $table): void {
            $table->foreignId('default_warehouse_id')->nullable()->index()->constrained('warehouses')->restrictOnDelete();
        });

        foreach (self::WAREHOUSES as $warehouse) {
            DB::table('warehouses')->insertOrIgnore([
                'code' => $warehouse['code'], 'name' => $warehouse['name'], 'default_part_types' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('part_subtypes')
                ->where('type', 'semi_finished')
                ->whereIn('name', $warehouse['subtypes'])
                ->update(['default_warehouse_id' => DB::table('warehouses')->where('code', $warehouse['code'])->value('id')]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Alüminyum ve mil depoları geçişi geri alınamaz.');
    }
};
