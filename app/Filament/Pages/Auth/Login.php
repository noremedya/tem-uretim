<?php

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\LogoutInactiveUser;
use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Giriş e-posta yerine kullanıcı adıyla yapılır. Pasif kullanıcılar User::canAccessPanel() ile reddedilir.
 * Oturumu açıkken pasifleştirilip çıkışa zorlanan kullanıcıya burada mesaj gösterilir.
 */
class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        if (session()->get(LogoutInactiveUser::SESSION_FLAG)) {
            Notification::make()
                ->danger()
                ->title(LogoutInactiveUser::MESSAGE)
                ->persistent()
                ->send();
        }
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Kullanıcı adı')
            ->required()
            ->autocomplete('username')
            ->autocapitalize('none')
            ->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => User::normalizeUsername($data['username']),
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
