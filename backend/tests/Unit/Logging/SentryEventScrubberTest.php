<?php

use App\Logging\SentryEventScrubber;
use Sentry\Event;

function scrub(array $request = [], array $extra = []): Event
{
    $e = Event::createEvent();
    $e->setRequest($request);
    $e->setExtra($extra);

    return (new SentryEventScrubber)($e);
}

it('redacts Authorization header', function () {
    $e = scrub(['headers' => ['Authorization' => 'Bearer abc']]);
    expect($e->getRequest()['headers']['Authorization'])->toBe('[REDACTED]');
});

it('redacts Authorization header case-insensitively', function () {
    $e = scrub(['headers' => ['authorization' => 'Bearer abc']]);
    expect($e->getRequest()['headers']['authorization'])->toBe('[REDACTED]');
});

it('redacts Cookie header', function () {
    $e = scrub(['headers' => ['Cookie' => 'session=xyz']]);
    expect($e->getRequest()['headers']['Cookie'])->toBe('[REDACTED]');
});

it('redacts password in request body', function () {
    $e = scrub(['data' => ['password' => 'hunter2']]);
    expect($e->getRequest()['data']['password'])->toBe('[REDACTED]');
});

it('redacts password_confirmation in request body', function () {
    $e = scrub(['data' => ['password_confirmation' => 'hunter2']]);
    expect($e->getRequest()['data']['password_confirmation'])->toBe('[REDACTED]');
});

it('redacts token in request body', function () {
    $e = scrub(['data' => ['token' => 't0p_secret']]);
    expect($e->getRequest()['data']['token'])->toBe('[REDACTED]');
});

it('redacts api_key in request body', function () {
    $e = scrub(['data' => ['api_key' => 'k_live_xxx']]);
    expect($e->getRequest()['data']['api_key'])->toBe('[REDACTED]');
});

it('redacts FAL_API_KEY in extra', function () {
    $e = scrub(extra: ['FAL_API_KEY' => 'k_live_xxx']);
    expect($e->getExtra()['FAL_API_KEY'])->toBe('[REDACTED]');
});

it('does not return null — it redacts, not drops', function () {
    $e = scrub(['headers' => ['Authorization' => 'Bearer abc']]);
    expect($e)->not->toBeNull();
});

it('leaves unrelated fields untouched', function () {
    $e = scrub(['data' => ['username' => 'alice', 'password' => 'hunter2']]);
    expect($e->getRequest()['data']['username'])->toBe('alice');
    expect($e->getRequest()['data']['password'])->toBe('[REDACTED]');
});
