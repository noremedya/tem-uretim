<?php

use App\Enums\PartType;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartService;
use App\Services\UserService;
use App\Support\AuditPresenter;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ProductionFixtures;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Yönetici']);
});

it('başarılı ve başarısız girişler ile çıkış yazılır; şifre yazılmaz', function () {
    $user = User::factory()->operator()->create(['username' => 'ali', 'password' => 'gizli-sifre-1']);

    Livewire::test(Login::class)->fillForm(['username' => 'ali', 'password' => 'yanlis-sifre'])->call('authenticate');
    Livewire::test(Login::class)->fillForm(['username' => 'ali', 'password' => 'gizli-sifre-1'])->call('authenticate');
    auth()->logout();

    $auth = Activity::inLog('auth')->orderBy('id')->get();
    expect($auth->pluck('event')->all())->toBe(['failed', 'login', 'logout'])
        ->and($auth[0]->getProperty('username'))->toBe('ali')
        ->and($auth[0]->getProperty('ip'))->not->toBeNull()
        ->and($auth[1]->causer_id)->toBe($user->id)
        ->and(json_encode($auth->toArray()))->not->toContain('gizli-sifre-1')->not->toContain('yanlis-sifre');
});

it('değişiklik detayı Türkçe alan adları, enum etiketleri ve bağlı kayıt adlarıyla gösterilir', function () {
    $this->actingAs($this->admin);
    $part = Part::factory()->create(['code' => 'HM-1', 'type' => PartType::DirectConsumable]);
    $newUnit = Unit::factory()->create(['name' => 'litre']);
    app(PartService::class)->update($part, ['type' => 'indirect_consumable', 'unit_id' => $newUnit->id, 'critical_level' => '5'], $part->lock_version);

    $activity = Activity::where('subject_type', 'part')->where('event', 'updated')->sole();
    $changes = collect(AuditPresenter::changes($activity))->keyBy('field');

    expect(AuditPresenter::subjectType('part'))->toBe('Ürün')
        ->and(AuditPresenter::subject($activity))->toBe('HM-1')
        ->and(AuditPresenter::event($activity))->toBe('Güncellendi')
        ->and($changes['Tür'])->toMatchArray(['old' => 'Direkt sarf', 'new' => 'Endirekt sarf'])
        ->and($changes['Birim']['new'])->toBe('litre')
        ->and($changes['Kritik seviye']['new'])->toBe('5.000');

    Livewire::test(ViewAuditLog::class, ['record' => $activity->getRouteKey()])
        ->assertSee('Endirekt sarf')->assertSee('litre')->assertSee('Yönetici');
});

it('rol değişikliği etiketleriyle gösterilir; şifre alanı hiç görünmez', function () {
    $this->actingAs($this->admin);
    $user = User::factory()->operator()->create();
    app(UserService::class)->update($user, ['roles' => [Role::Operator, Role::Warehouse], 'password' => 'yeni-sifre-99'], $user->lock_version);

    $roles = Activity::where('description', 'roles_updated')->sole();
    expect(AuditPresenter::event($roles))->toBe('Roller değişti')
        ->and(collect(AuditPresenter::changes($roles))->sole())->toMatchArray(['field' => 'Roller', 'old' => 'Saha operatörü', 'new' => 'Saha operatörü, Depo']);

    $html = $this->get(AuditLogResource::getUrl('index'))->assertOk()->getContent();
    expect($html)->not->toContain('yeni-sifre-99')->not->toContain('password');
});

it('liste filtreleri ve kayıt sayfasından "Geçmiş" ile tek kayda filtre', function () {
    $this->actingAs($this->admin);
    $f = ProductionFixtures::create();
    $wo = $f->motorWorkOrder($this->admin, 1);
    $otherWo = $f->motorWorkOrder($this->admin, 2);

    Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])
        ->assertActionVisible('auditTrail')
        ->assertActionHasUrl('auditTrail', AuditLogResource::getUrl('index', ['subject_type' => 'work_order', 'subject_id' => $wo->id]));

    $mine = Activity::where('subject_type', 'work_order')->where('subject_id', $wo->id)->get();
    $others = Activity::where('subject_type', 'work_order')->where('subject_id', $otherWo->id)->get();

    Livewire::withQueryParams(['subject_type' => 'work_order', 'subject_id' => $wo->id])
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($others)
        ->assertSee('İş emri #'.$wo->id.' kaydının geçmişi');

    Livewire::test(ListAuditLogs::class)
        ->filterTable('subject_type', 'part')
        ->assertCanNotSeeTableRecords($mine)
        ->filterTable('subject_type', 'work_order')
        ->assertCanSeeTableRecords($mine);
});

it('denetim izine yalnızca yönetici erişir; Geçmiş butonu diğerlerine görünmez', function (string $role) {
    $user = $role === 'accounting' ? User::factory()->withRoles(Role::Accounting)->create() : User::factory()->{$role}()->create();
    $this->actingAs($user);

    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();

    $f = ProductionFixtures::create();
    $wo = $f->motorWorkOrder($this->admin, 1, $role === 'operator' ? ['assigned_user_id' => $user->id] : []);
    if ($user->can('view', $wo)) {
        Livewire::test(ViewWorkOrder::class, ['record' => $wo->getRouteKey()])->assertActionHidden('auditTrail');
    }
})->with(['operator', 'warehouse', 'accounting']);

it('denetim izi kayıtları otomatik silinmez (temizlik komutu zamanlanmamış)', function () {
    $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

    expect($events)->not->toContain('activitylog:clean');
});
