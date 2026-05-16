<?php

namespace Database\Factories;

use App\Models\Fish;
use App\Models\User;
use App\Services\Fish\BreedCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fish> */
class FishFactory extends Factory
{
    protected $model = Fish::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $catalog = app(BreedCatalog::class)->all();
        $breed = $this->faker->randomElement($catalog);

        return [
            'user_id' => User::factory(),
            'nickname' => $this->faker->firstName(),
            'breed' => $breed['id'],
            'color_hex' => $breed['default_color'],
            'size' => $this->faker->numberBetween($breed['min_size'], $breed['max_size']),
            'source' => 'manual',
            'source_ref' => null,
        ];
    }
}
