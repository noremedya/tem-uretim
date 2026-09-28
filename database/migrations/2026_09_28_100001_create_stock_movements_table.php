<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->string('type', 30)->index();
            $table->decimal('quantity', 15, 3);
            $table->decimal('balance_before', 15, 3);
            $table->decimal('balance_after', 15, 3);
            $table->nullableMorphs('reference');
            // Ters kaydedilen hareket. Unique: bir hareket yalnızca bir kez ters kaydedilebilir.
            $table->foreignId('corrected_movement_id')->nullable()->unique()
                ->constrained('stock_movements')->restrictOnDelete();
            // Çift gönderim koruması: aynı form gönderimi ikinci hareket oluşturamaz.
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['part_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_type_check
                    CHECK (type IN ('stock_in', 'stock_out', 'production_consumption', 'count_adjustment', 'return', 'correction')),
                ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity <> 0),
                ADD CONSTRAINT stock_movements_balance_check
                    CHECK (balance_before >= 0 AND balance_after >= 0 AND balance_after = balance_before + quantity),
                ADD CONSTRAINT stock_movements_type_sign_check CHECK (
                    (type IN ('stock_in', 'return') AND quantity > 0)
                    OR (type IN ('stock_out', 'production_consumption') AND quantity < 0)
                    OR type IN ('count_adjustment', 'correction')
                ),
                ADD CONSTRAINT stock_movements_correction_check
                    CHECK ((type = 'correction') = (corrected_movement_id IS NOT NULL)),
                ADD CONSTRAINT stock_movements_description_check CHECK (
                    type NOT IN ('stock_out', 'count_adjustment', 'correction')
                    OR (description IS NOT NULL AND btrim(description) <> '')
                )
        SQL);

        // Stok hareketleri değiştirilemez ve silinemez (CLAUDE.md bölüm 3 kural 3).
        // Bu trigger'ları atlatan bir ayar/oturum değişkeni bilerek yoktur.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Stok hareketleri değiştirilemez veya silinemez (%). Düzeltme için ters kayıt kullanın.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER stock_movements_no_update_delete
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_immutable();

            CREATE TRIGGER stock_movements_no_truncate
                BEFORE TRUNCATE ON stock_movements
                FOR EACH STATEMENT EXECUTE FUNCTION stock_movements_immutable();
        SQL);

        // Satırlar arası kurallar (check constraint ile ifade edilemeyenler):
        // - Ters kayıt: aynı parça, tam ters miktar; ters kaydın kendisi ters kaydedilemez.
        // - Küsuratsız birimde küsuratlı miktar olamaz.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_validate_insert() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                original stock_movements%ROWTYPE;
                unit_allows_decimal boolean;
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

                SELECT u.allows_decimal INTO unit_allows_decimal
                FROM parts p JOIN units u ON u.id = p.unit_id
                WHERE p.id = NEW.part_id
                -- Birimin küsurat ayarı bu hareket commit edilene kadar değiştirilemesin (UnitService FOR UPDATE alır).
                FOR SHARE OF u;

                IF NOT unit_allows_decimal AND NEW.quantity <> trunc(NEW.quantity) THEN
                    RAISE EXCEPTION 'Bu parçanın birimi küsuratlı miktar kabul etmez.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_movements_validate_insert
                BEFORE INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_validate_insert();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_movements_immutable(); DROP FUNCTION IF EXISTS stock_movements_validate_insert();');
    }
};
