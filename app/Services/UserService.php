<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Kullanıcı yönetimi. Kurallar:
 * - Her kullanıcının en az bir rolü vardır.
 * - Kullanıcı kendini pasifleştiremez.
 * - Son aktif yöneticinin yönetici rolü kaldırılamaz ve pasifleştirilemez.
 *
 * Yönetici kümesini küçültebilecek her işlem, yönetici rol satırını kilitleyerek (lockForUpdate)
 * serileştirilir; böylece iki yönetici aynı anda birbirini pasifleştirip sistemi yöneticisiz bırakamaz.
 */
class UserService
{
    /**
     * @param  array{username: string, name: string, email?: ?string, password: string, roles: array<Role|string>}  $data
     */
    public function create(array $data): User
    {
        $roles = $this->normalizeRoles($data['roles'] ?? []);

        return DB::transaction(function () use ($data, $roles): User {
            $user = new User(Arr::only($data, ['username', 'name', 'email', 'password']));
            $user->is_active = true;
            $user->save();

            $this->syncRoles($user, $roles);

            return $user;
        });
    }

    /**
     * @param  array{username?: string, name?: string, email?: ?string, password?: ?string, roles?: array<Role|string>}  $data
     * @param  int  $expectedLockVersion  Düzenlemenin başladığı andaki lock_version.
     */
    public function update(User $user, array $data, int $expectedLockVersion): User
    {
        $roles = array_key_exists('roles', $data) ? $this->normalizeRoles($data['roles']) : null;

        return DB::transaction(function () use ($user, $data, $roles, $expectedLockVersion): User {
            $this->lockAdminRole();

            $user->fill(Arr::only($data, ['username', 'name', 'email']));

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            // Çakışma varsa burada StaleModelException fırlar ve transaction geri alınır.
            $user->saveExpectingVersion($expectedLockVersion);

            if ($roles !== null) {
                if (! in_array(Role::Admin, $roles, true)) {
                    $this->ensureNotLastActiveAdmin($user, 'Son aktif yöneticinin yönetici rolü kaldırılamaz.');
                }

                $this->syncRoles($user, $roles);
            }

            return $user;
        });
    }

    public function deactivate(User $user, User $actor): User
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException('Kendi hesabınızı pasifleştiremezsiniz.');
        }

        return DB::transaction(function () use ($user): User {
            $this->lockAdminRole();

            $this->ensureNotLastActiveAdmin($user, 'Son aktif yönetici pasifleştirilemez.');

            $user->is_active = false;
            $user->save();

            return $user;
        });
    }

    public function activate(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user->is_active = true;
            $user->save();

            return $user;
        });
    }

    /**
     * Kullanıcı şu an aktif bir yöneticiyse ve başka aktif yönetici yoksa işlemi reddeder.
     * Çağırmadan önce lockAdminRole() ile yönetici rol satırı kilitlenmiş olmalıdır.
     */
    private function ensureNotLastActiveAdmin(User $user, string $message): void
    {
        $activeAdmins = User::role(Role::Admin)->where('is_active', true);

        $userIsActiveAdmin = (clone $activeAdmins)->whereKey($user->getKey())->exists();

        if ($userIsActiveAdmin && ! (clone $activeAdmins)->whereKeyNot($user->getKey())->exists()) {
            throw new BusinessRuleException($message);
        }
    }

    private function lockAdminRole(): void
    {
        RoleModel::query()
            ->where('name', Role::Admin->value)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  list<Role>  $roles
     */
    private function syncRoles(User $user, array $roles): void
    {
        $old = $user->getRoleNames()->sort()->values()->all();
        $new = collect($roles)->map->value->sort()->values()->all();

        $user->syncRoles($roles);

        if ($old === $new) {
            return;
        }

        // Rol değişiklikleri pivot tabloda olduğu için model olayıyla loglanmaz; elle kaydedilir.
        activity()
            ->performedOn($user)
            ->event('updated')
            ->withChanges(['attributes' => ['roles' => $new], 'old' => ['roles' => $old]])
            ->log('roles_updated');
    }

    /**
     * @param  array<Role|string>  $roles
     * @return list<Role>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles = collect($roles)
            ->map(fn (Role|string $role): Role => $role instanceof Role ? $role : Role::from($role))
            ->unique()
            ->values()
            ->all();

        if ($roles === []) {
            throw new BusinessRuleException('Kullanıcının en az bir rolü olmalıdır.');
        }

        return $roles;
    }
}
