<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = app(UserService::class);
});

it('birden fazla rolle kullanıcı oluşturur', function () {
    $user = $this->service->create([
        'username' => 'Depo.Operator',
        'name' => 'Mehmet',
        'email' => '',
        'password' => 'gizli-sifre',
        'roles' => [Role::Operator, 'warehouse'],
    ]);

    expect($user->username)->toBe('depo.operator')
        ->and($user->email)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->lock_version)->toBe(0)
        ->and($user->getRoleNames()->sort()->values()->all())->toBe(['operator', 'warehouse']);
});

it('rolsüz kullanıcı oluşturulamaz ve güncellemede tüm roller kaldırılamaz', function () {
    expect(fn () => $this->service->create([
        'username' => 'rolsuz', 'name' => 'Rolsüz', 'password' => 'gizli-sifre', 'roles' => [],
    ]))->toThrow(BusinessRuleException::class, 'en az bir rolü');

    $user = User::factory()->operator()->create();

    expect(fn () => $this->service->update($user, ['roles' => []], $user->lock_version))
        ->toThrow(BusinessRuleException::class, 'en az bir rolü');

    expect(User::count())->toBe(1)
        ->and($user->fresh()->hasRole(Role::Operator))->toBeTrue();
});

it('kullanıcı kendini pasifleştiremez', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    expect(fn () => $this->service->deactivate($admin, $admin))
        ->toThrow(BusinessRuleException::class, 'Kendi hesabınızı');

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($admin->can('deactivate', $admin))->toBeFalse();
});

it('son aktif yönetici pasifleştirilemez', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create(); // pasif yönetici sayılmaz
    $actor = User::factory()->operator()->create();

    expect(fn () => $this->service->deactivate($admin, $actor))
        ->toThrow(BusinessRuleException::class, 'Son aktif yönetici pasifleştirilemez.');

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('başka aktif yönetici varsa yönetici pasifleştirilebilir', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->service->deactivate($other, $admin);

    expect($other->fresh()->is_active)->toBeFalse();
});

it('son aktif yöneticinin yönetici rolü kaldırılamaz', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create();

    expect(fn () => $this->service->update($admin, ['name' => 'Yeni ad', 'roles' => [Role::Operator]], $admin->lock_version))
        ->toThrow(BusinessRuleException::class, 'Son aktif yöneticinin yönetici rolü kaldırılamaz.');

    // Transaction geri alındı: ne ad ne rol ne sürüm değişti.
    $fresh = $admin->fresh();
    expect($fresh->hasRole(Role::Admin))->toBeTrue()
        ->and($fresh->hasRole(Role::Operator))->toBeFalse()
        ->and($fresh->name)->not->toBe('Yeni ad')
        ->and($fresh->lock_version)->toBe(0);
});

it('başka aktif yönetici varsa yönetici rolü kaldırılabilir; diğer roller serbestçe değişir', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->service->update($other, ['roles' => [Role::Warehouse]], $other->lock_version);
    expect($other->fresh()->getRoleNames()->all())->toBe(['warehouse']);

    // Tek yönetici artık $admin; yönetici rolünü koruyup rol eklemek serbest.
    $this->service->update($admin, ['roles' => [Role::Admin, Role::Operator]], $admin->lock_version);
    expect($admin->fresh()->getRoleNames()->sort()->values()->all())->toBe(['admin', 'operator']);
});

it('pasif kullanıcı yeniden aktifleştirilebilir', function () {
    $user = User::factory()->operator()->inactive()->create();

    $this->service->activate($user);

    expect($user->fresh()->is_active)->toBeTrue();
});

it('veritabanı kullanıcı adı biçimini ve benzersizliği zorlar', function (array $row) {
    $base = ['name' => 'x', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()];
    DB::table('users')->insert([...$base, 'username' => 'mevcut', 'email' => 'a@example.com']);

    expect(fn () => DB::transaction(fn () => DB::table('users')->insert([...$base, ...$row])))
        ->toThrow(QueryException::class);
})->with([
    'büyük harf' => [['username' => 'Buyuk']],
    'Türkçe karakter' => [['username' => 'şükrü']],
    'çok kısa' => [['username' => 'ab']],
    'tekrar eden kullanıcı adı' => [['username' => 'mevcut']],
    'tekrar eden e-posta' => [['username' => 'yeni', 'email' => 'a@example.com']],
    'boş e-posta' => [['username' => 'yeni', 'email' => '']],
    'negatif sürüm' => [['username' => 'yeni', 'lock_version' => -1]],
]);
