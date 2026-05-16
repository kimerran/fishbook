<?php

namespace App\Services\Backgrounds\Prompts;

use App\Exceptions\Backgrounds\DisallowedPromptException;
use Illuminate\Contracts\Config\Repository;

class PromptDenylist
{
    public function __construct(private readonly Repository $config) {}

    public function assertAllowed(string $prompt): void
    {
        $needles = (array) $this->config->get('services.fal.prompt_denylist', []);
        $hay = mb_strtolower($prompt);
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($hay, mb_strtolower((string) $n))) {
                throw new DisallowedPromptException('Prompt contains disallowed content.');
            }
        }
    }
}
