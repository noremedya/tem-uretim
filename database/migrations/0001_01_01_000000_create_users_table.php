<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // Kullanıcı adı küçük harf, 3-50 karakter: a-z, 0-9, nokta, alt çizgi, tire (User::USERNAME_PATTERN ile aynı).
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_username_format_check
            CHECK (username ~ '^[a-z0-9._-]{3,50}$')
        SQL);
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_not_blank_check CHECK (email IS NULL OR email <> '')");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_lock_version_check CHECK (lock_version >= 0)');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
