<?php

use function Pest\Laravel\getJson;

it('stamps security headers on public health route', function () {
    $r = getJson('/api/v1/health');
    $r->assertOk();
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($r->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($r->headers->get('Permissions-Policy'))->toContain('camera=()');
    // Tests run in 'testing' env (non-production) → report-only CSP.
    expect($r->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull();
    expect($r->headers->get('Strict-Transport-Security'))->toBeNull();
});
