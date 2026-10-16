<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 4a (CLAUDE.md bölüm 4–5):
 * - Üretim hareket tipleri: production_output (+), production_scrap (−), shipment (−).
 * - Mamul kartında elle hareket yok: yalnızca production_output, shipment, correction (trigger).
 * - Her motor modelinin mamul kartı (finished_part_id); mevcut modeller için kart açılır.
 * - Stok kartına bağlı, çok seviyeli reçete (bom_items).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->updateMovementRules();
        $this->addFinishedParts();
        $this->createBomItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
        DB::unprepared('DROP FUNCTION IF EXISTS bom_items_validate();');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS motor_models_finished_part_check ON motor_models;
            DROP FUNCTION IF EXISTS motor_models_finished_part_check();
        SQL);

        // Mamul kartları ve bakiyeleri kalır (hareketleri olabilir); yalnızca bağlantı kaldırılır.
        Schema::table('motor_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finished_part_id');
        });

        if (DB::table('stock_movements')->whereIn('type', ['production_output', 'production_scrap', 'shipment'])->exists()) {
            throw new RuntimeException('Üretim veya sevk hareketi varken bu migration geri alınamaz.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
                DROP CONSTRAINT stock_movements_type_check,
                DROP CONSTRAINT stock_movements_type_sign_check,
                ADD CONSTRAINT stock_movements_type_check
                    CHECK (type IN ('stock_in', 'stock_out', 'production_consumption', 'count_adjustment', 'return', 'correction')),
                ADD CONSTRAINT stock_movements_type_sign_check CHECK (
                    (type IN ('stock_in', 'return') AND quantity > 0)
                    OR (type IN ('stock_out', 'production_consumption') AND quantity < 0)
                    OR type IN ('count_adjustment', 'correction')
                )
        SQL);

        $this->createValidateInsertFunction(withFinishedGoodRule: false);
    }

    private function updateMovementRules(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
                DROP CONSTRAINT stock_movements_type_check,
                DROP CONSTRAINT stock_movements_type_sign_check,
                ADD CONSTRAINT stock_movements_type_check CHECK (type IN (
                    'stock_in', 'stock_out', 'production_consumption', 'production_scrap', 'production_output',
                    'shipment', 'count_adjustment', 'return', 'correction'
                )),
                ADD CONSTRAINT stock_movements_type_sign_check CHECK (
                    (type IN ('stock_in', 'return', 'production_output') AND quantity > 0)
                    OR (type IN ('stock_out', 'production_consumption', 'production_scrap', 'shipment') AND quantity < 0)
                    OR type IN ('count_adjustment', 'correction')
                )
        SQL);

        $this->createValidateInsertFunction(withFinishedGoodRule: true);
    }

    /**
     * Satırlar arası kurallar. Fonksiyon gövdesi 2026_09_28_100001'deki ile aynıdır; yeni olan mamul kuralıdır.
     */
    private function createValidateInsertFunction(bool $withFinishedGoodRule): void
    {
        $finishedGoodRule = $withFinishedGoodRule ? <<<'SQL'
                -- Mamul kartında elle hareket yok (CLAUDE.md bölüm 5 "Mamul ve yarı mamul").
                IF part_type = 'finished_good' AND NEW.type NOT IN ('production_output', 'shipment', 'correction') THEN
                    RAISE EXCEPTION 'Mamul kartında elle stok hareketi yapılamaz; mamul stoğu yalnızca üretim ve sevkle değişir.'
                        USING ERRCODE = 'check_violation';
                END IF;
        SQL : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION stock_movements_validate_insert() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            DECLARE
                original stock_movements%ROWTYPE;
                unit_allows_decimal boolean;
                part_type text;
            BEGIN
                IF NEW.type = 'correction' THEN
                    SELECT * INTO original FROM stock_movements WHERE id = NEW.corrected_movement_id;

                    IF original.type = 'correction' THEN
                        RAISE EXCEPTION 'Ters kaydın kendisi ters kaydedilemez.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF original.part_id <> NEW.part_id OR NEW.quantity <> -original.quantity THEN
                        RAISE EXCEPTION 'Ters kayıt, orijinal hareketle aynı parçada ve tam ters miktarda olmalıdır.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                SELECT u.allows_decimal, p.type INTO unit_allows_decimal, part_type
                FROM parts p JOIN units u ON u.id = p.unit_id
                WHERE p.id = NEW.part_id
                -- Birimin küsurat ayarı bu hareket commit edilene kadar değiştirilemesin (UnitService FOR UPDATE alır).
                FOR SHARE OF u;

                IF NOT unit_allows_decimal AND NEW.quantity <> trunc(NEW.quantity) THEN
                    RAISE EXCEPTION 'Bu parçanın birimi küsuratlı miktar kabul etmez.' USING ERRCODE = 'check_violation';
                END IF;
                {$finishedGoodRule}
                RETURN NEW;
            END;
            \$\$;
        SQL);
    }

    private function addFinishedParts(): void
    {
        Schema::table('motor_models', function (Blueprint $table) {
            $table->foreignId('finished_part_id')->nullable()->unique()->constrained('parts')->restrictOnDelete();
        });

        // Mevcut modeller için mamul kartı: kod ve ad modelinkiyle aynı, birim "Adet".
        $models = DB::table('motor_models')->orderBy('id')->get();

        if ($models->isNotEmpty()) {
            $unitId = DB::table('units')->whereRaw('lower(name) = ?', ['adet'])->value('id')
                ?? DB::table('units')->insertGetId([
                    'name' => 'Adet', 'allows_decimal' => false, 'lock_version' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

            foreach ($models as $model) {
                if (DB::table('parts')->where('code', $model->code)->exists()) {
                    throw new RuntimeException("Motor modeli {$model->code} için mamul kartı açılamadı: bu kodla bir stok kartı zaten var. Kartın kodunu değiştirip migration'ı tekrar çalıştırın.");
                }

                $partId = DB::table('parts')->insertGetId([
                    'code' => $model->code, 'name' => $model->name, 'unit_id' => $unitId, 'type' => 'finished_good',
                    'critical_level' => 0, 'is_active' => $model->is_active, 'lock_version' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('stock_balances')->insert(['part_id' => $partId, 'quantity' => 0, 'is_below_critical' => false, 'updated_at' => now()]);
                DB::table('motor_models')->where('id', $model->id)->update(['finished_part_id' => $partId]);
            }
        }

        DB::statement('ALTER TABLE motor_models ALTER COLUMN finished_part_id SET NOT NULL');

        // Modelin bağlı olduğu kart mamul olmalı.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION motor_models_finished_part_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT type FROM parts WHERE id = NEW.finished_part_id) <> 'finished_good' THEN
                    RAISE EXCEPTION 'Motor modelinin mamul kartı mamul türünde olmalıdır.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER motor_models_finished_part_check
                BEFORE INSERT OR UPDATE OF finished_part_id ON motor_models
                FOR EACH ROW EXECUTE FUNCTION motor_models_finished_part_check();
        SQL);
    }

    private function createBomItems(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_part_id')->constrained('parts')->restrictOnDelete();
            $table->foreignId('component_part_id')->index()->constrained('parts')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 15, 3);
            // Standart fire yüzdesi.
            $table->decimal('scrap_rate', 5, 2)->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            // parent_part_id için de index görevi görür.
            $table->unique(['parent_part_id', 'component_part_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bom_items
                ADD CONSTRAINT bom_items_quantity_check CHECK (quantity_per_unit > 0),
                ADD CONSTRAINT bom_items_scrap_rate_check CHECK (scrap_rate >= 0 AND scrap_rate < 100),
                ADD CONSTRAINT bom_items_not_self_check CHECK (parent_part_id <> component_part_id),
                ADD CONSTRAINT bom_items_lock_version_check CHECK (lock_version >= 0)
        SQL);

        // Tür kuralları (check constraint başka tabloya bakamaz). Döngü kontrolü BomService'te, kilit altında.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bom_items_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT type FROM parts WHERE id = NEW.parent_part_id) NOT IN ('semi_finished', 'finished_good') THEN
                    RAISE EXCEPTION 'Reçete yalnızca yarı mamul veya mamul kartına tanımlanabilir.' USING ERRCODE = 'check_violation';
                END IF;

                IF (SELECT type FROM parts WHERE id = NEW.component_part_id) IN ('indirect_consumable', 'finished_good') THEN
                    RAISE EXCEPTION 'Endirekt sarf ve mamul reçeteye bileşen olarak eklenemez.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bom_items_validate
                BEFORE INSERT OR UPDATE ON bom_items
                FOR EACH ROW EXECUTE FUNCTION bom_items_validate();
        SQL);
    }
};
