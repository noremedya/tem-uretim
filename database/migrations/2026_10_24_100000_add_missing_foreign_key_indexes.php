<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genel gözden geçirme (Aşama 11): index'i olmayan foreign key kolonları ve denetim izinin tarih kolonu.
 * Kural: tek kolonlu her foreign key'in ilk kolonu olduğu bir index bulunur (tests/Feature/Database/IndexTest.php).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const FOREIGN_KEYS = [
        'role_has_permissions' => ['role_id'],
        'parts' => ['unit_id'],
        'stock_movements' => ['user_id'],
        'work_order_status_histories' => ['user_id'],
        'motor_units' => ['shipped_by_user_id'],
        'exchange_rates' => ['user_id'],
        'invoices' => ['issued_by_user_id'],
        'cheques' => ['cash_account_id'],
        'payments' => ['user_id'],
        'account_transactions' => ['user_id'],
        'cash_movements' => ['user_id'],
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }

        // Denetim izi kalıcıdır ve ekran tarihe göre sıralar/filtreler.
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        foreach (self::FOREIGN_KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
