<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $cachedPassword;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'username' => $this->faker->unique()->userName(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => static::$cachedPassword ??= Hash::make('password-supersafe-1234'),
            'google_id' => null,
            'is_admin' => false,
            'email_verified_at' => now(),
        ];
    }

    public function googleOnly(): static
    {
        return $this->state(fn () => [
            'password' => null,
            'google_id' => (string) $this->faker->unique()->randomNumber(8, true),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true]);
    }
}
