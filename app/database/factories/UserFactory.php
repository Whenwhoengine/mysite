<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRole;
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** 後臺管理員（測試用；正式帳號只由 mysite:admin:create 建立）。 */
    public function admin(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill(['role' => UserRole::Admin]));
    }

    /** 後臺唯讀人員（測試用）。 */
    public function viewer(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill(['role' => UserRole::Viewer]));
    }

    /** 已停用帳號（測試用）。 */
    public function disabled(): static
    {
        return $this->afterMaking(static fn (User $user) => $user->forceFill(['disabled_at' => now()]));
    }
}
