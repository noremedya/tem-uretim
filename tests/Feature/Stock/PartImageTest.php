<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Models\User;
use App\Services\PartService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Stok kartı görseli: yerel diskte saklanır, yalnızca kartı görebilen oturumlu kullanıcıya servis edilir;
 * yükleme stok kartı düzenleme yetkisiyle.
 */

beforeEach(function () {
    Storage::fake(PartService::IMAGE_DISK);
    $this->admin = User::factory()->admin()->create();
    $this->part = Part::factory()->create(['code' => 'RUL-6204']);
});

it('yönetici düzenleme formundan görsel yükler; kart sayfasında ve adresinden görünür', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditPart::class, ['record' => $this->part->getRouteKey()])
        ->fillForm(['image_path' => UploadedFile::fake()->image('rulman.jpg', 800, 600)])
        ->call('save')
        ->assertHasNoFormErrors();

    $part = $this->part->fresh();
    expect($part->image_path)->toStartWith(PartService::IMAGE_DIRECTORY.'/');
    Storage::disk(PartService::IMAGE_DISK)->assertExists($part->image_path);

    $this->get(PartResource::getUrl('view', ['record' => $part]))->assertOk()->assertSee($part->imageUrl(), false);
    $this->get($part->imageUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('görsel değiştirilince eski dosya silinir; kaldırılınca dosya silinir', function () {
    $service = app(PartService::class);
    $disk = Storage::disk(PartService::IMAGE_DISK);
    $disk->put('part-images/eski.jpg', 'x');
    $disk->put('part-images/yeni.jpg', 'y');

    $part = $service->update($this->part, ['image_path' => 'part-images/eski.jpg'], $this->part->lock_version);
    $part = $service->update($part, ['image_path' => 'part-images/yeni.jpg'], $part->lock_version);
    $disk->assertMissing('part-images/eski.jpg');

    $service->update($part, ['image_path' => null], $part->lock_version);
    $disk->assertMissing('part-images/yeni.jpg');
    expect($part->fresh()->image_path)->toBeNull();
});

it('görsel yolu yalnızca görsel klasöründeki bir resim olabilir', function (string $path) {
    expect(fn () => app(PartService::class)->update($this->part, ['image_path' => $path], $this->part->lock_version))
        ->toThrow(BusinessRuleException::class, 'Geçersiz görsel');
})->with(['../private/report-exports/x.xlsx', 'part-images/../report-exports/a.jpg', 'report-exports/a.jpg', 'part-images/a.pdf']);

it('görsel olmayan dosya yüklenemez', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditPart::class, ['record' => $this->part->getRouteKey()])
        ->fillForm(['image_path' => UploadedFile::fake()->create('belge.pdf', 10, 'application/pdf')])
        ->call('save')
        ->assertHasFormErrors(['image_path']);
});

it('görseli kartı görebilen her rol görür; oturumsuz erişilemez; görseli olmayan kart 404', function () {
    Storage::disk(PartService::IMAGE_DISK)->put('part-images/r.jpg', UploadedFile::fake()->image('r.jpg')->getContent());
    $part = app(PartService::class)->update($this->part, ['image_path' => 'part-images/r.jpg'], $this->part->lock_version);

    foreach ([Role::Operator, Role::Warehouse, Role::Accounting] as $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())->get($part->imageUrl())->assertOk();
    }

    auth()->logout();
    $this->get($part->imageUrl())->assertRedirect(route('filament.app.auth.login'));

    $this->actingAs($this->admin)->get(route('parts.image', Part::factory()->create()))->assertNotFound();
});

it('stok kartı düzenleyemeyen (depo) görsel yükleyemez', function () {
    $this->actingAs(User::factory()->warehouse()->create());

    $this->get(PartResource::getUrl('edit', ['record' => $this->part]))->assertForbidden();
});
