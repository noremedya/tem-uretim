<?php

namespace App\Models;

use App\Models\Concerns\HasOptimisticLocking;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['username', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasOptimisticLocking, HasRoles, LogsActivity, Notifiable;

    /** Veritabanındaki users_username_format_check ile aynı kural. */
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,50}$/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Kullanıcı adında Türkçe karakterlerin ASCII karşılıkları. */
    private const USERNAME_TRANSLITERATION = [
        'ş' => 's', 'Ş' => 's',
        'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u',
        'ö' => 'o', 'Ö' => 'o',
        'ı' => 'i', 'İ' => 'i',
    ];

    /**
     * Kullanıcı adı normalizasyonu (form, giriş, komut ve model aynı kuralı kullanır):
     * boşluklar kırpılır, Türkçe karakterler ASCII'ye çevrilir, büyük harfler küçültülür.
     */
    public static function normalizeUsername(?string $username): string
    {
        return mb_strtolower(strtr(trim((string) $username), self::USERNAME_TRANSLITERATION));
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeUsername($value));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => filled($value) ? mb_strtolower(trim($value)) : null);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // password ve remember_token ayrıca config/activitylog.php'de de genel olarak dışlanır.
        return LogOptions::defaults()
            ->logOnly(['username', 'name', 'email', 'is_active'])
            ->logExcept(['password', 'remember_token'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
