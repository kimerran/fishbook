<?php

use App\Exceptions\Backgrounds\DisallowedPromptException;
use App\Services\Backgrounds\Prompts\PromptDenylist;

it('passes an allowed prompt', function () {
    app(PromptDenylist::class)->assertAllowed('a calm coral reef at dusk');
    expect(true)->toBeTrue();
});

it('throws on a denylisted substring', function () {
    expect(fn () => app(PromptDenylist::class)->assertAllowed('something nsfw here'))
        ->toThrow(DisallowedPromptException::class);
});

it('is case-insensitive', function () {
    expect(fn () => app(PromptDenylist::class)->assertAllowed('UPPER NUDE PROMPT'))
        ->toThrow(DisallowedPromptException::class);
});
