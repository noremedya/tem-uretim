<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kullanıcı bilgileri')
                    ->columns(2)
                    ->schema([
                        TextInput::make('username')
                            ->label('Kullanıcı adı')
                            ->required()
                            ->maxLength(50)
                            ->regex(User::USERNAME_PATTERN)
                            ->validationMessages([
                                'regex' => 'Kullanıcı adı 3-50 karakter olmalı; yalnızca küçük harf, rakam, nokta, alt çizgi ve tire içerebilir.',
                            ])
                            ->unique(ignoreRecord: true)
                            ->autocomplete('off')
                            ->autocapitalize('none')
                            // Türkçe karakterler ve büyük harfler hata vermeden düzeltilir: alandan çıkınca
                            // kullanıcı düzeltilmiş hâli görür; doğrulama ve kayıt da her durumda bu hâli kullanır.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('username', User::normalizeUsername($state)))
                            ->mutateStateForValidationUsing(fn (?string $state): string => User::normalizeUsername($state))
                            ->dehydrateStateUsing(fn (?string $state): string => User::normalizeUsername($state))
                            ->helperText('Girişte kullanılır. Türkçe karakterler ve büyük harfler otomatik düzeltilir (Şükrü → sukru).'),
                        TextInput::make('name')
                            ->label('Ad soyad')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-posta')
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('İsteğe bağlı.'),
                    ]),
                Section::make('Roller')
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->options(Role::class)
                            ->required()
                            ->minItems(1)
                            ->validationMessages([
                                'required' => 'En az bir rol seçilmelidir.',
                                'min' => 'En az bir rol seçilmelidir.',
                            ])
                            ->columns(3),
                    ]),
                Section::make('Şifre')
                    ->columns(2)
                    ->schema([
                        TextInput::make('password')
                            ->label('Şifre')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->confirmed()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Değiştirmek istemiyorsanız boş bırakın.'
                                : 'En az 8 karakter.'),
                        TextInput::make('password_confirmation')
                            ->label('Şifre (tekrar)')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
