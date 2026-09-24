<?php

use App\Enums\Role;
use App\Exceptions\StaleModelException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('güncelleme tek atomik sorguyla yapılır: WHERE id = ? AND lock_version = ?', function () {
    $user = User::factory()->operator()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, '"users"')) {
            $queries[] = $query;
        }
    });

    $user->name = 'Yeni ad';
    $user->save();

    // users tablosuna giden ilk sorgu UPDATE'tir (önce okuyup karşılaştırma yok), tek UPDATE vardır
    // ve sürüm koşulu WHERE içindedir. (Sonraki SELECT, activitylog'un kaydı yazarken yaptığı okumadır.)
    $updates = array_values(array_filter($queries, fn ($q) => str_starts_with($q->sql, 'update')));
    expect($updates)->toHaveCount(1)
        ->and($queries[0])->toBe($updates[0]);
    expect($queries[0]->sql)->toStartWith('update "users" set')
        ->toContain('"lock_version" = ?')
        ->toMatch('/where "id" = \? and "lock_version" = \?$/')
        ->and(array_slice($queries[0]->bindings, -2))->toBe([$user->id, 0])
        ->and($user->lock_version)->toBe(1)
        ->and($user->fresh()->lock_version)->toBe(1);
});

it('eski sürümle kaydetmek StaleModelException fırlatır ve veriyi değiştirmez', function () {
    $user = User::factory()->operator()->create(['name' => 'İlk']);

    $first = User::find($user->id);
    $second = User::find($user->id);

    $first->name = 'Birinci';
    $first->save();

    $second->name = 'İkinci';
    expect(fn () => $second->save())->toThrow(StaleModelException::class);

    expect($user->fresh())
        ->name->toBe('Birinci')
        ->lock_version->toBe(1);
});

it('saveExpectingVersion verilen sürümü bekler; kirli alan yoksa bile sürümü kontrol eder', function () {
    $user = User::factory()->operator()->create();
    User::find($user->id)->update(['name' => 'Başkası değiştirdi']); // sürüm 1

    $fresh = User::find($user->id);
    expect(fn () => $fresh->saveExpectingVersion(0))->toThrow(StaleModelException::class);

    $fresh = User::find($user->id);
    $fresh->saveExpectingVersion(1);
    expect($fresh->fresh()->lock_version)->toBe(2);
});

it('Filament: iki ayrı oturumda aynı kayıt düzenlenirse ikinci kayıt reddedilir', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    $target = User::factory()->operator()->create(['name' => 'Eski ad']);

    // İki yönetici formu aynı anda açar (ikisi de sürüm 0'ı görür).
    $this->actingAs($adminA);
    $sessionA = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);

    $this->actingAs($adminB);
    $sessionB = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);

    // A kaydeder.
    $this->actingAs($adminA);
    $sessionA->fillForm(['name' => 'A adı'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->lock_version)->toBe(1);

    // B formu doldurur: her istekte Livewire kaydı veritabanından yeniden okur (sürüm 1),
    // ama form açıldığı andaki sürümü (0) taşır.
    $this->actingAs($adminB);
    $sessionB->fillForm(['name' => 'B adı']);

    expect($sessionB->instance()->getRecord()->lock_version)->toBe(1)
        ->and($sessionB->get('lockVersion'))->toBe(0);

    // Yeniden okuma çakışmayı gizlemez: kayıt reddedilir.
    $sessionB->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($sessionB->instance()->getRecord()->lock_version)->toBe(1)
        ->and($target->fresh())
        ->name->toBe('A adı')
        ->lock_version->toBe(1);

    // A aynı formda tekrar kaydedebilir (kendi sürümü güncellendi).
    $this->actingAs($adminA);
    $sessionA->fillForm(['name' => 'A adı 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh())
        ->name->toBe('A adı 2')
        ->lock_version->toBe(2);
});

it('Filament: yalnızca rol değişikliği de sürüm kontrolünden geçer', function () {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();
    $target = User::factory()->operator()->create();

    $this->actingAs($adminB);
    $sessionB = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);

    $this->actingAs($adminA);
    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['name' => 'A değiştirdi'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->actingAs($adminB);
    $sessionB->fillForm(['roles' => [Role::Warehouse->value]])
        ->call('save')
        ->assertNotified('Kayıt başka biri tarafından değiştirildi');

    expect($target->fresh()->getRoleNames()->all())->toBe(['operator']);
});

it('Filament: formun taşıdığı sürüm istemciden değiştirilemez', function () {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->operator()->create();

    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->set('lockVersion', 99);
})->throws(CannotUpdateLockedPropertyException::class);
