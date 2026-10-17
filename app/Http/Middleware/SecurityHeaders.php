<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Güvenlik başlıkları (Aşama 11). Content-Security-Policy yalnızca uygulamanın kendi sunucusundan kaynak
 * yüklemeye izin verir; CDN/harici kaynak yasağı (CLAUDE.md bölüm 3 kural 1) tarayıcıda da zorlanır.
 *
 * Livewire ve Alpine satır içi betik/stil ve ifade değerlendirmesi kullandığı için 'unsafe-inline' ve
 * 'unsafe-eval' gerekir; harici sunucu yine yasaktır. Kamera (Hızlı İşlem) yalnızca kendi sayfalarımıza açıktır.
 * Geliştirmede `npm run dev` çalışıyorsa Vite sunucusuna izin verilir.
 */
class SecurityHeaders
{
    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    public function contentSecurityPolicy(): string
    {
        $dev = $this->viteDevServer();
        $self = trim("'self' {$dev}");

        return implode('; ', [
            "default-src 'self'",
            "script-src {$self} 'unsafe-inline' 'unsafe-eval'",
            "style-src {$self} 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src {$self} data:",
            'connect-src '.trim("'self' {$dev} ".str_replace(['http://', 'https://'], ['ws://', 'wss://'], $dev)),
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    /** `npm run dev` çalışıyorsa Vite sunucusunun adresi (public/hot), değilse boş. */
    private function viteDevServer(): string
    {
        if (! $this->vite->isRunningHot()) {
            return '';
        }

        $url = trim((string) file_get_contents($this->vite->hotFile()));

        return parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : '');
    }
}
