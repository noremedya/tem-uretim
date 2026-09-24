<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role as RoleModel;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin
        {--username= : Kullanıcı adı (giriş için)}
        {--name= : Ad soyad}
        {--email= : E-posta (isteğe bağlı)}
        {--password= : Şifre (verilmezse sorulur)}';

    protected $description = 'Yönetici rolünde bir kullanıcı oluşturur (ilk kurulum için)';

    public function handle(UserService $users): int
    {
        if (! RoleModel::query()->where('name', Role::Admin->value)->exists()) {
            $this->error('Yönetici rolü bulunamadı. Önce şu komutu çalıştırın: php artisan db:seed --class=RolesAndPermissionsSeeder --force');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive();

        $username = $this->option('username') ?? ($interactive ? text(
            label: 'Kullanıcı adı',
            placeholder: 'ör. ahmet.yilmaz',
            required: true,
            hint: 'Küçük harf, rakam, nokta, alt çizgi, tire; 3-50 karakter.',
        ) : null);
        $name = $this->option('name') ?? ($interactive ? text(label: 'Ad soyad', required: true) : null);
        $email = $this->option('email') ?? ($interactive ? text(label: 'E-posta (isteğe bağlı)') : null);

        $password = $this->option('password');
        $passwordConfirmation = $password;

        if ($password === null && $interactive) {
            $password = password(label: 'Şifre', required: true, hint: 'En az 8 karakter.');
            $passwordConfirmation = password(label: 'Şifre (tekrar)', required: true);
        }

        $data = [
            'username' => User::normalizeUsername($username),
            'name' => $name,
            'email' => filled($email) ? mb_strtolower(trim($email)) : null,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'regex:'.User::USERNAME_PATTERN, Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], attributes: [
            'username' => 'kullanıcı adı',
            'name' => 'ad soyad',
            'email' => 'e-posta',
            'password' => 'şifre',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $users->create([
                ...$validator->safe()->only(['username', 'name', 'email', 'password']),
                'roles' => [Role::Admin],
            ]);
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Yönetici oluşturuldu: {$user->username}");

        return self::SUCCESS;
    }
}
