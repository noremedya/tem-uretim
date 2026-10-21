<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama C — Alt türler: 6 ana tür sabit; altına kullanıcı tanımlı tek seviye alt türler. Ürünün alt türü ürünün
 * ana türüne ait olmalıdır (trigger). Kurulumda örnek liste açılır (ekrandan düzenlenir/pasifleştirilir).
 */
return new class extends Migration
{
    private const INITIAL = [
        'raw_material' => ['Silisli sac', 'Mil çeliği', 'Alüminyum', 'Bakır tel'],
        'component' => ['Rulman', 'Klemens kutusu', 'Pervane', 'Pervane koruma tası', 'Kondansatör', 'Eksantrik saç'],
        'direct_consumable' => ['Vernik', 'Makaron', 'Kablo', 'Bağlantı elemanı'],
        'semi_finished' => ['Stator paketi', 'Rotor paketi', 'Bobinajlı stator', 'Enjekte rotor', 'İşlenmiş mil', 'Mil+rotor', 'Gövde', 'Kapak'],
        'finished_good' => ['Trifaze', 'Monofaze MD', 'Monofaze MSD', 'Vibrasyon'],
    ];

    public function up(): void
    {
        Schema::create('part_subtypes', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE part_subtypes
                ADD CONSTRAINT part_subtypes_name_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT part_subtypes_type_check CHECK (type IN (
                    'raw_material', 'component', 'direct_consumable', 'indirect_consumable', 'semi_finished', 'finished_good'));
            -- Aynı ana türde ad tekil (büyük/küçük harf, Türkçe I/ı ve baştaki/sondaki boşluk önemsiz).
            CREATE UNIQUE INDEX part_subtypes_type_name_unique ON part_subtypes (type, translate(lower(btrim(name)), 'ı', 'i'));
            SQL);

        Schema::table('parts', function (Blueprint $table): void {
            $table->foreignId('subtype_id')->nullable()->index()->constrained('part_subtypes')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION parts_subtype_matches_type() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.subtype_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM part_subtypes s WHERE s.id = NEW.subtype_id AND s.type = NEW.type
                ) THEN
                    RAISE EXCEPTION 'Alt tür, ürünün ana türüne ait olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER parts_subtype_matches_type BEFORE INSERT OR UPDATE OF subtype_id, type ON parts
                FOR EACH ROW EXECUTE FUNCTION parts_subtype_matches_type();

            -- Kullanılan alt türün ana türü değiştirilemez.
            CREATE OR REPLACE FUNCTION part_subtypes_type_locked() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type <> OLD.type AND EXISTS (SELECT 1 FROM parts p WHERE p.subtype_id = OLD.id) THEN
                    RAISE EXCEPTION 'Ürünlerde kullanılan alt türün ana türü değiştirilemez.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER part_subtypes_type_locked BEFORE UPDATE OF type ON part_subtypes
                FOR EACH ROW EXECUTE FUNCTION part_subtypes_type_locked();
            SQL);

        foreach (self::INITIAL as $type => $names) {
            foreach ($names as $name) {
                DB::table('part_subtypes')->insert(['type' => $type, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS parts_subtype_matches_type ON parts; DROP FUNCTION IF EXISTS parts_subtype_matches_type()');
        Schema::table('parts', fn (Blueprint $table) => $table->dropConstrainedForeignId('subtype_id'));
        Schema::dropIfExists('part_subtypes');
        DB::unprepared('DROP FUNCTION IF EXISTS part_subtypes_type_locked()');
    }
};
