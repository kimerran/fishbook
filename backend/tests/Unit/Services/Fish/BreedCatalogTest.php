<?php

use App\Services\Fish\BreedCatalog;

it('finds a known breed', function () {
    $cat = app(BreedCatalog::class);
    expect($cat->find('guppy'))->not->toBeNull();
    expect($cat->find('guppy')['min_size'])->toBe(8);
});

it('returns null for an unknown breed', function () {
    expect(app(BreedCatalog::class)->find('shark'))->toBeNull();
});

it('clamps undersized values to min', function () {
    expect(app(BreedCatalog::class)->clampSize('guppy', 1))->toBe(8);
});

it('clamps oversized values to max', function () {
    expect(app(BreedCatalog::class)->clampSize('guppy', 99))->toBe(18);
});

it('validates a good triple', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 12, '#FF6B9D'))->toBe([]);
});

it('reports a bad breed', function () {
    expect(app(BreedCatalog::class)->validate('shark', 12, '#FF6B9D'))
        ->toHaveKey('breed');
});

it('reports a bad size for a known breed', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 99, '#FF6B9D'))
        ->toHaveKey('size');
});

it('reports a bad color', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 12, 'red'))
        ->toHaveKey('color_hex');
});
