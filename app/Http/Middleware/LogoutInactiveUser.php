<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oturumu açıkken pasifleştirilen kullanıcıyı bir sonraki isteğinde çıkışa zorlar ve giriş
 * ekranına yönlendirir. Panelde persistent tanımlıdır; Livewire isteklerinde de çalışır
 * (Livewire, yönlendirmeyi tarayıcıda tam sayfa yönlendirmesine çevirir).
 */
class LogoutInactiveUser
{
    public const SESSION_FLAG = 'auth.deactivated';

    public const MESSAGE = 'Hesabınız pasifleştirildi, yöneticinize başvurun.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && ! $user->is_active) {
            Filament::auth()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash(self::SESSION_FLAG, true);

            return redirect()->to(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
