<?php

namespace Database\Factories;

use App\Models\Background;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Background> */
class BackgroundFactory extends Factory
{
    /** @var class-string<Background> */
    protected $model = Background::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'upload',
            'storage_key' => 'backgrounds/u-test/'.Str::ulid()->toBase32().'.webp',
            'width' => 1920,
            'height' => 1080,
            'prompt' => null,
            'is_active' => false,
        ];
    }

    public function generated(string $prompt = 'a calm coral reef'): static
    {
        return $this->state(fn () => ['kind' => 'generated', 'prompt' => $prompt]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
