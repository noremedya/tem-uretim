<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 5a (CLAUDE.md bölüm 4–5): cari hesap alanları, döviz kurları, satış fiyatları.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Mevcut kayıtlar müşteridir.
            $table->boolean('is_customer')->default(true)->index();
            $table->boolean('is_supplier')->default(false)->index();
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->string('default_currency', 3)->default('TRY');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_kind_check CHECK (is_customer OR is_supplier),
                ADD CONSTRAINT customers_payment_term_check CHECK (payment_term_days BETWEEN 0 AND 365),
                ADD CONSTRAINT customers_default_currency_check CHECK (default_currency IN ('TRY', 'EUR', 'USD'))
        SQL);

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->date('rate_date');
            $table->decimal('forex_buying', 18, 6);
            $table->decimal('forex_selling', 18, 6);
            $table->string('source', 10);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['currency', 'rate_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE exchange_rates
                ADD CONSTRAINT exchange_rates_currency_check CHECK (currency IN ('EUR', 'USD')),
                ADD CONSTRAINT exchange_rates_rate_check CHECK (forex_buying > 0 AND forex_selling > 0),
                ADD CONSTRAINT exchange_rates_source_check CHECK (source IN ('tcmb', 'manual')),
                ADD CONSTRAINT exchange_rates_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motor_model_id')->constrained()->restrictOnDelete();
            // Boşsa liste fiyatı.
            $table->foreignId('customer_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('unit_price', 15, 2);
            $table->date('valid_from');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prices
                ADD CONSTRAINT prices_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT prices_unit_price_check CHECK (unit_price > 0),
                ADD CONSTRAINT prices_lock_version_check CHECK (lock_version >= 0)
        SQL);

        // Liste fiyatında customer_id NULL'dur; NULLS NOT DISTINCT ile aynı gün için tek liste fiyatı olur.
        DB::statement('CREATE UNIQUE INDEX prices_model_customer_from_unique ON prices (motor_model_id, customer_id, valid_from) NULLS NOT DISTINCT');

        // Sipariş ve müşteri fiyatı yalnızca müşteri işaretli cariye bağlanır.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ensure_customer_kind() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.customer_id IS NOT NULL AND NOT (SELECT is_customer FROM customers WHERE id = NEW.customer_id) THEN
                    RAISE EXCEPTION 'Seçilen cari hesap müşteri olarak işaretli değil.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER orders_customer_kind
                BEFORE INSERT OR UPDATE OF customer_id ON orders
                FOR EACH ROW EXECUTE FUNCTION ensure_customer_kind();

            CREATE TRIGGER prices_customer_kind
                BEFORE INSERT OR UPDATE OF customer_id ON prices
                FOR EACH ROW EXECUTE FUNCTION ensure_customer_kind();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS orders_customer_kind ON orders;
            DROP TRIGGER IF EXISTS prices_customer_kind ON prices;
            DROP FUNCTION IF EXISTS ensure_customer_kind();
        SQL);
        Schema::dropIfExists('prices');
        Schema::dropIfExists('exchange_rates');

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                DROP CONSTRAINT customers_kind_check,
                DROP CONSTRAINT customers_payment_term_check,
                DROP CONSTRAINT customers_default_currency_check
        SQL);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['is_customer', 'is_supplier', 'payment_term_days', 'default_currency']);
        });
    }
};
