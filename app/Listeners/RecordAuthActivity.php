<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Girişler denetim izine yazılır (CLAUDE.md bölüm 5 "Denetim izi"): başarılı giriş, başarısız giriş (denenen
 * kullanıcı adı) ve çıkış; IP ile. Şifre hiçbir zaman yazılmaz.
 */
class RecordAuthActivity
{
    public function handleLogin(Login $event): void
    {
        $this->record('login', $event->user instanceof User ? $event->user : null);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $event->user instanceof User ? $event->user : null);
    }

    public function handleFailed(Failed $event): void
    {
        $this->record('failed', $event->user instanceof User ? $event->user : null, (string) ($event->credentials['username'] ?? ''));
    }

    private function record(string $event, ?User $user, ?string $attemptedUsername = null): void
    {
        $logger = activity('auth')->event($event)->withProperties(array_filter([
            'ip' => request()->ip(),
            'username' => $attemptedUsername ?: $user?->username,
        ]));

        if ($user !== null) {
            $logger->performedOn($user)->causedBy($user);
        } else {
            $logger->causedByAnonymous();
        }

        $logger->log($event);
    }
}
