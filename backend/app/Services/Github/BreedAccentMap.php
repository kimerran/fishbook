<?php

namespace App\Services\Github;

use Illuminate\Contracts\Config\Repository;

class BreedAccentMap
{
    public function __construct(private readonly Repository $config) {}

    public function for(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }
        /** @var array<string, string> $map */
        $map = (array) $this->config->get('services.github.language_colors', []);

        return $map[$language] ?? null;
    }
}
