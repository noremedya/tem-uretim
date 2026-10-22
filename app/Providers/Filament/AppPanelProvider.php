<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\LogoutInactiveUser;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->brandName(fn (): string => config('app.name'))
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            // CDN yok: Inter, Filament paketindeki yerel dosyalardan (public/fonts/filament) yüklenir.
            ->viteTheme('resources/css/filament/app/theme.css')
            ->font('Inter Variable', provider: LocalFontProvider::class)
            // Ortak Alpine bileşenleri (submitGuard vb.), Vite ile derlenir.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render("@vite('resources/js/filament/app.js')"))
            // Sol menüde arama kutusu.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): string => view('filament.nav-search')->render())
            // Demo (tanıtım) sunucusunda her sayfada, giriş dahil, uyarı şeridi.
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => app()->environment(config('demo.environment')) ? view('filament.demo-banner')->render() : '')
            ->databaseNotifications()
            // Varsayılan avatar ui-avatars.com'a gider; yerel baş harf avatarı kullanılır.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent: Livewire istekleri dahil her istekte çalışır; Authenticate'ten önce gelmeli.
            ->authMiddleware([
                LogoutInactiveUser::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
