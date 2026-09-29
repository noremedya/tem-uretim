<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            // VKN (10 hane) veya TCKN (11 hane); isteğe bağlı, doluysa benzersiz.
            $table->string('tax_number', 11)->nullable()->unique();
            $table->string('tax_office', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_name_not_blank_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT customers_tax_number_format_check CHECK (tax_number IS NULL OR tax_number ~ '^[0-9]{10,11}$'),
                ADD CONSTRAINT customers_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Otomatik: NumberSequenceService (varsayılan SIP-{YIL}-{SIRA:5}).
            $table->string('order_number', 50)->unique();
            $table->string('customer_reference', 100)->nullable()->index();
            $table->foreignId('customer_id')->index()->constrained()->restrictOnDelete();
            $table->date('order_date')->index();
            $table->date('due_date')->index();
            $table->string('status', 20)->default('open')->index();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_status_check CHECK (status IN ('open', 'partial', 'completed', 'cancelled')),
                ADD CONSTRAINT orders_order_number_not_blank_check CHECK (btrim(order_number) <> ''),
                ADD CONSTRAINT orders_due_date_check CHECK (due_date >= order_date),
                -- İptal edilen siparişin açıklaması zorunlu; iptal edilmemiş siparişte iptal açıklaması olmaz.
                ADD CONSTRAINT orders_cancellation_reason_check CHECK (
                    (status = 'cancelled') = (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
                ),
                ADD CONSTRAINT orders_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('motor_model_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // Aynı model bir siparişte tek kalemdir (order_id için de index görevi görür).
            $table->unique(['order_id', 'motor_model_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};
