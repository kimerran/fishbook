<?php

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\WeakPasswordException;
use App\Models\User;
use App\Services\Auth\AuthService;

it('registers a user with a strong password', function () {
    $service = app(AuthService::class);

    $user = $service->register(
        username: 'alice',
        email: 'alice@example.com',
        password: 'a-strong-pass-123!'
    );

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->username)->toBe('alice')
        ->and($user->password)->not->toBe('a-strong-pass-123!')
        ->and(strlen($user->password))->toBeGreaterThan(40);
});

it('rejects a weak password (zxcvbn < 2)', function () {
    $service = app(AuthService::class);

    expect(fn () => $service->register('bob', 'bob@example.com', 'password'))
        ->toThrow(WeakPasswordException::class);
});

it('authenticates with correct credentials', function () {
    $user = User::factory()->create([
        'username' => 'carol',
        'password' => bcrypt('correct-horse-battery'),
    ]);

    $service = app(AuthService::class);

    expect($service->verifyCredentials('carol', 'correct-horse-battery')->id)
        ->toBe($user->id);
});

it('throws InvalidCredentialsException for wrong password', function () {
    User::factory()->create(['username' => 'dave', 'password' => bcrypt('right-pw')]);

    expect(fn () => app(AuthService::class)->verifyCredentials('dave', 'wrong-pw'))
        ->toThrow(InvalidCredentialsException::class);
});

it('throws InvalidCredentialsException for unknown username', function () {
    expect(fn () => app(AuthService::class)->verifyCredentials('ghost', 'whatever'))
        ->toThrow(InvalidCredentialsException::class);
});
