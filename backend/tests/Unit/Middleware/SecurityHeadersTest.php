<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('sets baseline headers + enforced CSP + HSTS in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $resp = (new SecurityHeaders)->handle(Request::create('/x'), fn () => new Response);

    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($resp->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($resp->headers->get('Permissions-Policy'))->toContain('camera=()');
    expect($resp->headers->get('Permissions-Policy'))->toContain('microphone=()');
    expect($resp->headers->get('Permissions-Policy'))->toContain('geolocation=()');
    expect($resp->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
    expect($resp->headers->get('Content-Security-Policy'))->not->toBeNull();
    expect($resp->headers->get('Content-Security-Policy'))->not->toContain('localhost:9000');
    expect($resp->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    expect($resp->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

it('uses report-only CSP + no HSTS + MinIO in img-src locally', function () {
    app()->detectEnvironment(fn () => 'local');
    $resp = (new SecurityHeaders)->handle(Request::create('/x'), fn () => new Response);

    expect($resp->headers->get('Strict-Transport-Security'))->toBeNull();
    expect($resp->headers->get('Content-Security-Policy'))->toBeNull();
    expect($resp->headers->get('Content-Security-Policy-Report-Only'))->toContain('localhost:9000');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('X-Frame-Options'))->toBe('DENY');
});
