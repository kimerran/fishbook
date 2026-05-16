<?php

namespace App\Services\Fish;

use Illuminate\Contracts\Config\Repository;

class BreedCatalog
{
    public function __construct(private readonly Repository $config) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> $breeds */
        $breeds = $this->config->get('fish_breeds', []);

        return $breeds;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $breed) {
            if ($breed['id'] === $id) {
                return $breed;
            }
        }

        return null;
    }

    public function clampSize(string $breed, int $size): int
    {
        $b = $this->find($breed);
        if ($b === null) {
            return $size;
        }

        return max($b['min_size'], min($b['max_size'], $size));
    }

    /** @return array<string, array<int, string>> */
    public function validate(string $breed, int $size, string $colorHex): array
    {
        $errors = [];
        $b = $this->find($breed);
        if ($b === null) {
            $errors['breed'] = ['Unknown breed.'];
        } elseif ($size < $b['min_size'] || $size > $b['max_size']) {
            $errors['size'] = ["Size must be between {$b['min_size']} and {$b['max_size']} for {$breed}."];
        }
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex) !== 1) {
            $errors['color_hex'] = ['Color must be #RRGGBB.'];
        }

        return $errors;
    }
}
