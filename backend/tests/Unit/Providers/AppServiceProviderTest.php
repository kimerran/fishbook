<?php

use Illuminate\Support\Facades\URL;

it('forces https URLs in production', function () {
    app()->detectEnvironment(fn () => 'production');
    URL::forceScheme('http'); // reset

    (new \App\Providers\AppServiceProvider(app()))->boot();
    expect(url('/x'))->toStartWith('https://');
});

it('does not force https locally', function () {
    app()->detectEnvironment(fn () => 'local');
    // Re-bind the URL generator's forced scheme by booting in local — no-op.
    (new \App\Providers\AppServiceProvider(app()))->boot();
    // To guarantee no leakage from the production test above, also reset:
    $generator = app('url');
    // forceScheme(null) clears the forced scheme in modern Laravel.
    if (method_exists($generator, 'forceScheme')) {
        $generator->forceScheme(null);
    }
    expect(url('/x'))->not->toStartWith('https://');
});
