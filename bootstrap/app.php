<?php

use App\Http\Middleware\LogoutInactiveUser;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Üretimde Caddy ve Cloudflare Tunnel arkasında çalışır; doğru şema/IP için proxy başlıklarına güven.
        // Uygulama portu dışarı açılmaz, yalnızca bu proxy'ler erişebilir.
        $middleware->trustProxies(at: '*');

        // Pasif kullanıcıyı çıkışa zorlayan middleware, Authenticate'in 403 vermesinden önce çalışmalı.
        // Authenticate öncelik listesinde olduğu için sıralamada öne alınır; bu yüzden listeye ekliyoruz.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: LogoutInactiveUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
