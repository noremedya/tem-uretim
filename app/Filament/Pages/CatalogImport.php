<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\BusinessRuleNotification;
use App\Imports\CatalogTemplate;
use App\Imports\ImportResult;
use App\Imports\StarterCatalog;
use App\Services\CatalogImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Ürün yapısını Excel'den içe aktarma (yönetici): şablon/başlangıç dosyası indirme, yükleme, kontrol (önizleme) ve
 * hata yoksa tek seferde aktarma. Kurallar CatalogImportService'te.
 */
class CatalogImport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'İçe aktar (Excel)';

    protected static ?string $title = 'Ürün yapısını Excel\'den içe aktar';

    protected static ?string $slug = 'ice-aktar';

    private const DISK = 'local';

    private const DIRECTORY = 'imports';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Son kontrolün sonucu (ImportResult::toArray). */
    #[Locked]
    public ?array $result = null;

    /** Kontrol edilen dosya ve seçenek: aktarma yalnızca aynı dosya ve seçenekle yapılır. */
    #[Locked]
    public ?string $checkedKey = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can(Permission::PartsManage) && $user->can(Permission::MotorModelsManage) && $user->can(Permission::StockMove);
    }

    public function mount(): void
    {
        $this->form->fill(['update' => false]);

        // Aktarılmadan bırakılan yüklemeler bir gün sonra silinir.
        $disk = Storage::disk(self::DISK);
        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label('Excel dosyası (.xlsx)')
                    ->disk(self::DISK)
                    ->directory(self::DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->maxSize(10240)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetCheck()),
                Toggle::make('update')
                    ->label('Mevcutları güncelle')
                    ->helperText('Kapalıysa sistemde zaten olan kodlar atlanır. Açıksa dosyada dolu olan alanlar güncellenir (ad, açıklama, marka, alt tür, barkod, kritik seviye; reçete miktarları).')
                    ->live()
                    ->afterStateUpdated(fn () => $this->resetCheck()),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('Önce şablonu (veya size özel hazırlanmış başlangıç dosyasını) indirip doldurun. Yüklenen dosya önce kontrol edilir; hiçbir şey kaydedilmez. Hata yoksa "Aktar" ile tümü tek seferde kaydedilir; tek satır bile reddedilirse hiçbir şey kaydedilmez.')
                ->schema([
                    EmbeddedSchema::make('form'),
                    Actions::make([
                        Action::make('check')
                            ->label('Kontrol et')
                            ->icon(Heroicon::OutlinedMagnifyingGlass)
                            ->action(fn () => $this->check()),
                        Action::make('import')
                            ->label('Aktar')
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->color('success')
                            ->visible(fn (): bool => $this->canImport())
                            ->requiresConfirmation()
                            ->modalDescription('Kontrol edilen dosya kaydedilecek. Devam edilsin mi?')
                            ->action(fn () => $this->import()),
                    ])->key('import-actions'),
                ]),
            View::make('filament.pages.catalog-import-result')->visible(fn (): bool => $this->result !== null),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Boş şablonu indir')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): BinaryFileResponse => $this->download('urun-yapisi-sablonu.xlsx', [])),
            Action::make('starter')
                ->label('Başlangıç dosyasını indir')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(fn (): BinaryFileResponse => $this->download('urun-yapisi-baslangic.xlsx', StarterCatalog::rows(), StarterCatalog::guide())),
        ];
    }

    public function importResult(): ?ImportResult
    {
        return $this->result === null ? null : ImportResult::fromArray($this->result);
    }

    private function check(): void
    {
        abort_unless(static::canAccess(), 403);
        $path = $this->filePath();

        if ($path === null) {
            return;
        }

        try {
            $result = app(CatalogImportService::class)->check($path, Auth::user(), (bool) ($this->data['update'] ?? false));
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);
            $this->resetCheck();

            return;
        }

        $this->result = $result->toArray();
        $this->checkedKey = $this->key();

        $result->hasErrors()
            ? Notification::make()->danger()->title(sprintf('%d satırda hata var', $result->errorCount()))->body('Hataları düzeltip dosyayı yeniden yükleyin.')->send()
            : Notification::make()->success()->title('Dosya kontrol edildi, hata yok')->body('"Aktar" ile kaydedebilirsiniz.')->send();
    }

    private function import(): void
    {
        abort_unless(static::canAccess() && $this->canImport(), 403);
        $path = $this->filePath();

        try {
            $result = app(CatalogImportService::class)->import($path, Auth::user(), (bool) ($this->data['update'] ?? false));
        } catch (BusinessRuleException $exception) {
            BusinessRuleNotification::send($exception);

            return;
        }

        $this->result = $result->toArray();

        if (! $result->committed) {
            $this->checkedKey = null;
            Notification::make()->danger()->title('Aktarılamadı; hiçbir şey kaydedilmedi')->body('Bu arada değişen kayıtlar olabilir; sonuçları inceleyin.')->send();

            return;
        }

        Storage::disk(self::DISK)->delete($this->storedPath());
        $this->checkedKey = null;
        $this->form->fill(['update' => false]);
        Notification::make()->success()->title('İçe aktarma tamamlandı')->send();
    }

    private function canImport(): bool
    {
        $result = $this->importResult();

        return $result !== null && ! $result->committed && ! $result->hasErrors() && $this->checkedKey !== null && $this->checkedKey === $this->key();
    }

    private function resetCheck(): void
    {
        $this->result = null;
        $this->checkedKey = null;
    }

    private function key(): ?string
    {
        $stored = $this->storedPath();

        return $stored === null ? null : md5($stored.'|'.(int) (bool) ($this->data['update'] ?? false));
    }

    /** Formdaki dosyanın diskteki göreli yolu (yükleme bitmişse). */
    private function storedPath(): ?string
    {
        $state = $this->form->getRawState()['file'] ?? null;
        $file = is_array($state) ? reset($state) : $state;

        return is_string($file) && str_starts_with($file, self::DIRECTORY.'/') && ! str_contains($file, '..') ? $file : null;
    }

    private function filePath(): ?string
    {
        $this->form->getState();
        $stored = $this->storedPath();

        if ($stored === null || ! Storage::disk(self::DISK)->exists($stored)) {
            Notification::make()->warning()->title('Önce bir Excel dosyası yükleyin')->send();

            return null;
        }

        return Storage::disk(self::DISK)->path($stored);
    }

    private function download(string $name, array $rows, array $guide = []): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'sablon').'.xlsx';
        app(CatalogTemplate::class)->write($path, $rows, $guide);

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
