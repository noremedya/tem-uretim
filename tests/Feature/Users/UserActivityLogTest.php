<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\UserService;
use Spatie\Activitylog\Models\Activity;

function allActivityJson(): string
{
    return Activity::all()
        ->map(fn (Activity $a) => json_encode([$a->attribute_changes, $a->properties], JSON_UNESCAPED_UNICODE))
        ->implode("\n");
}

it('kullanıcı değişikliklerini kaydeder, şifre ve remember_token yazmaz', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $service = app(UserService::class);
    $user = $service->create([
        'username' => 'ahmet', 'name' => 'Ahmet', 'password' => 'ilk-sifre-123', 'roles' => [Role::Operator],
    ]);
    $service->update($user, ['name' => 'Ahmet Yılmaz', 'password' => 'yeni-sifre-456'], $user->lock_version);

    $user->setRememberToken('gizli-token-degeri');
    $user->save();

    $activities = Activity::where('subject_type', 'user')->where('subject_id', $user->id)->get();

    expect($activities->pluck('event')->all())->toContain('created', 'updated')
        ->and($activities->firstWhere('description', 'updated')->causer_id)->toBe($admin->id);

    $json = allActivityJson();
    expect($json)
        ->toContain('Ahmet Yılmaz')
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('gizli-token-degeri')
        ->not->toContain($user->fresh()->password);
});

it('rol değişikliklerini kaydeder', function () {
    $user = User::factory()->operator()->create();

    app(UserService::class)->update($user, ['roles' => [Role::Operator, Role::Warehouse]], $user->lock_version);

    $activity = Activity::where('description', 'roles_updated')->where('subject_id', $user->id)->sole();

    expect($activity->attribute_changes['old']['roles'])->toBe(['operator'])
        ->and($activity->attribute_changes['attributes']['roles'])->toBe(['operator', 'warehouse']);
});
