<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->regexify('[a-z]{6}[0-9]{3}'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Rol(ler) atar. Roller RolesAndPermissionsSeeder ile oluşturulmuş olmalıdır.
     */
    public function withRoles(Role ...$roles): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles($roles));
    }

    public function admin(): static
    {
        return $this->withRoles(Role::Admin);
    }

    public function operator(): static
    {
        return $this->withRoles(Role::Operator);
    }

    public function warehouse(): static
    {
        return $this->withRoles(Role::Warehouse);
    }
}
