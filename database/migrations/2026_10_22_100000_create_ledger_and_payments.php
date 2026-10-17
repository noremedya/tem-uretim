<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 5c (CLAUDE.md bölüm 4–5): cari hareketleri, kasa/banka, tahsilat/ödeme, çek/senet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->index();
            $table->string('name', 100);
            $table->string('currency', 3);
            $table->string('bank_name', 100)->nullable();
            $table->string('branch', 100)->nullable();
            $table->string('iban', 34)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_accounts
                ADD CONSTRAINT cash_accounts_kind_check CHECK (kind IN ('cash', 'bank')),
                ADD CONSTRAINT cash_accounts_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT cash_accounts_name_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT cash_accounts_bank_check CHECK (kind = 'cash' OR (bank_name IS NOT NULL AND btrim(bank_name) <> '')),
                ADD CONSTRAINT cash_accounts_lock_version_check CHECK (lock_version >= 0)
        SQL);
        // Para birimi başına tek kasa.
        DB::statement("CREATE UNIQUE INDEX cash_accounts_one_cash_per_currency ON cash_accounts (currency) WHERE kind = 'cash'");

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->index();
            $table->string('kind', 10);
            $table->foreignId('customer_id')->index()->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->string('bank_name', 100)->nullable();
            $table->string('drawer', 255)->nullable();
            $table->date('due_date')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('status', 15)->index();
            $table->foreignId('cash_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cheques
                ADD CONSTRAINT cheques_direction_check CHECK (direction IN ('received', 'issued')),
                ADD CONSTRAINT cheques_kind_check CHECK (kind IN ('cheque', 'note')),
                ADD CONSTRAINT cheques_status_check CHECK (status IN ('portfolio', 'collected', 'endorsed', 'bounced', 'paid', 'cancelled')),
                ADD CONSTRAINT cheques_amount_check CHECK (amount > 0),
                ADD CONSTRAINT cheques_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT cheques_number_check CHECK (btrim(number) <> ''),
                ADD CONSTRAINT cheques_status_direction_check CHECK (
                    (direction = 'received' AND status IN ('portfolio', 'collected', 'endorsed', 'bounced', 'cancelled'))
                    OR (direction = 'issued' AND status IN ('portfolio', 'paid', 'cancelled'))
                ),
                ADD CONSTRAINT cheques_account_check CHECK ((status IN ('collected', 'paid')) = (cash_account_id IS NOT NULL)),
                ADD CONSTRAINT cheques_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->index();
            $table->foreignId('customer_id')->index()->constrained()->restrictOnDelete();
            $table->date('payment_date')->index();
            $table->string('method', 15);
            $table->foreignId('cash_account_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('cheque_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('account_currency', 3);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('account_amount', 15, 2);
            $table->text('description')->nullable();
            $table->string('status', 10)->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_direction_check CHECK (direction IN ('received', 'made')),
                ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'bank', 'cheque', 'note', 'endorsement')),
                ADD CONSTRAINT payments_endorsement_check CHECK (method <> 'endorsement' OR direction = 'made'),
                ADD CONSTRAINT payments_target_check CHECK (
                    (method IN ('cash', 'bank') AND cash_account_id IS NOT NULL AND cheque_id IS NULL)
                    OR (method IN ('cheque', 'note', 'endorsement') AND cheque_id IS NOT NULL AND cash_account_id IS NULL)
                ),
                ADD CONSTRAINT payments_amount_check CHECK (amount > 0 AND account_amount > 0 AND exchange_rate > 0),
                ADD CONSTRAINT payments_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD') AND account_currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT payments_status_check CHECK (status IN ('posted', 'cancelled')),
                ADD CONSTRAINT payments_cancellation_check CHECK (
                    (status = 'cancelled') = (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
                ),
                ADD CONSTRAINT payments_lock_version_check CHECK (lock_version >= 0)
        SQL);

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unique(['payment_id', 'invoice_id']);
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('type', 20)->index();
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->nullableMorphs('reference');
            $table->foreignId('corrected_transaction_id')->nullable()->unique()->constrained('account_transactions')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'currency']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE account_transactions
                ADD CONSTRAINT account_transactions_type_check CHECK (type IN ('opening', 'invoice', 'payment', 'cheque_bounced', 'correction')),
                ADD CONSTRAINT account_transactions_currency_check CHECK (currency IN ('TRY', 'EUR', 'USD')),
                ADD CONSTRAINT account_transactions_amount_check CHECK (amount <> 0),
                ADD CONSTRAINT account_transactions_correction_check CHECK ((type = 'correction') = (corrected_transaction_id IS NOT NULL))
        SQL);
        // Cari ve para birimi başına tek açılış kaydı.
        DB::statement("CREATE UNIQUE INDEX account_transactions_one_opening ON account_transactions (customer_id, currency) WHERE type = 'opening'");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->index()->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date')->index();
            $table->nullableMorphs('reference');
            $table->foreignId('corrected_movement_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_movements
                ADD CONSTRAINT cash_movements_type_check CHECK (type IN ('opening', 'payment', 'cheque_collected', 'cheque_paid', 'correction')),
                ADD CONSTRAINT cash_movements_amount_check CHECK (amount <> 0),
                ADD CONSTRAINT cash_movements_correction_check CHECK ((type = 'correction') = (corrected_movement_id IS NOT NULL))
        SQL);
        DB::statement("CREATE UNIQUE INDEX cash_movements_one_opening ON cash_movements (cash_account_id) WHERE type = 'opening'");

        // Cari ve kasa/banka hareketleri değiştirilemez ve silinemez (düzeltme ters kayıtla).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Cari ve kasa/banka hareketleri değiştirilemez veya silinemez (%). Düzeltme için ters kayıt kullanın.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER account_transactions_immutable BEFORE UPDATE OR DELETE ON account_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
            CREATE TRIGGER account_transactions_no_truncate BEFORE TRUNCATE ON account_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();
            CREATE TRIGGER cash_movements_immutable BEFORE UPDATE OR DELETE ON cash_movements
                FOR EACH ROW EXECUTE FUNCTION ledger_immutable();
            CREATE TRIGGER cash_movements_no_truncate BEFORE TRUNCATE ON cash_movements
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_immutable();

            -- Ters kayıt, orijinal hareketin tam tersidir (aynı cari/hesap ve para birimi).
            CREATE OR REPLACE FUNCTION ledger_validate_correction() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                ok boolean;
            BEGIN
                IF NEW.type <> 'correction' THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'account_transactions' THEN
                    SELECT o.type <> 'correction' AND o.customer_id = NEW.customer_id AND o.currency = NEW.currency AND o.amount = -NEW.amount
                    INTO ok FROM account_transactions o WHERE o.id = NEW.corrected_transaction_id;
                ELSE
                    SELECT o.type <> 'correction' AND o.cash_account_id = NEW.cash_account_id AND o.amount = -NEW.amount
                    INTO ok FROM cash_movements o WHERE o.id = NEW.corrected_movement_id;
                END IF;

                IF NOT COALESCE(ok, false) THEN
                    RAISE EXCEPTION 'Ters kayıt, orijinal hareketin tam tersi olmalıdır; ters kaydın kendisi ters kaydedilemez.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER account_transactions_validate BEFORE INSERT ON account_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_validate_correction();
            CREATE TRIGGER cash_movements_validate BEFORE INSERT ON cash_movements
                FOR EACH ROW EXECUTE FUNCTION ledger_validate_correction();
        SQL);

        $this->backfillInvoices();
    }

    /** Aşama 5b'de kesilmiş faturaların cari hareketleri (iptal edilmişse ters kaydıyla). */
    private function backfillInvoices(): void
    {
        $invoices = DB::table('invoices')->whereIn('status', ['issued', 'cancelled'])->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $sign = ($invoice->direction === 'sales') === ($invoice->kind === 'normal') ? 1 : -1;
            $amount = bcmul((string) $invoice->grand_total, (string) $sign, 2);

            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $id = DB::table('account_transactions')->insertGetId([
                'customer_id' => $invoice->customer_id, 'currency' => $invoice->currency, 'type' => 'invoice',
                'amount' => $amount, 'transaction_date' => $invoice->invoice_date, 'due_date' => $invoice->due_date,
                'reference_type' => 'invoice', 'reference_id' => $invoice->id,
                'description' => 'Fatura (geriye dönük kayıt)', 'user_id' => $invoice->issued_by_user_id, 'created_at' => $invoice->issued_at,
            ]);

            if ($invoice->status === 'cancelled') {
                DB::table('account_transactions')->insert([
                    'customer_id' => $invoice->customer_id, 'currency' => $invoice->currency, 'type' => 'correction',
                    'amount' => bcmul($amount, '-1', 2), 'transaction_date' => substr((string) $invoice->cancelled_at, 0, 10),
                    'reference_type' => 'invoice', 'reference_id' => $invoice->id, 'corrected_transaction_id' => $id,
                    'description' => 'Fatura iptali (geriye dönük kayıt)', 'user_id' => $invoice->issued_by_user_id, 'created_at' => $invoice->cancelled_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('cash_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_immutable(); DROP FUNCTION IF EXISTS ledger_validate_correction();');
    }
};
