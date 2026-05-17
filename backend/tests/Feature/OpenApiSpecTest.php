<?php

it('emits bare paths (no /api/v1 prefix) under paths', function () {
    $spec = json_decode(file_get_contents(storage_path('api-docs/openapi.json')), true);
    expect($spec['paths'] ?? [])->not->toBeEmpty();
    foreach (array_keys($spec['paths']) as $path) {
        expect($path)->not->toStartWith('/api/v1');
    }
});

it('declares /api/v1 exactly once via servers[0].url', function () {
    $spec = json_decode(file_get_contents(storage_path('api-docs/openapi.json')), true);
    expect($spec['servers'][0]['url'] ?? null)->toBe('/api/v1');
});
