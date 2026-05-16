<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => RateLimiter::clear('auth'));

it('registers a user happy path', function () {
    $payload = [
        'username' => 'newuser',
        'email' => 'new@example.com',
        'password' => 'a-strong-pass-123!',
        'password_confirmation' => 'a-strong-pass-123!',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertCreated()
        ->assertJsonStructure(['user' => ['id', 'username', 'email', 'is_admin'], 'token']);

    expect(User::where('username', 'newuser')->exists())->toBeTrue();
});

it('rejects a duplicate username', function () {
    User::factory()->create(['username' => 'taken']);

    $this->postJson('/api/v1/auth/register', [
        'username' => 'taken',
        'email' => 'fresh@example.com',
        'password' => 'a-strong-pass-123!',
        'password_confirmation' => 'a-strong-pass-123!',
    ])->assertStatus(422)->assertJsonValidationErrors('username');
});

it('rejects a weak password', function () {
    $this->postJson('/api/v1/auth/register', [
        'username' => 'weakguy',
        'email' => 'weak@example.com',
        'password' => 'password11',
        'password_confirmation' => 'password11',
    ])->assertStatus(422);
});

it('rejects mismatched confirmation', function () {
    $this->postJson('/api/v1/auth/register', [
        'username' => 'mismatch',
        'email' => 'mm@example.com',
        'password' => 'a-strong-pass-123!',
        'password_confirmation' => 'different-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('rate-limits register at 5 per minute per (ip, username)', function () {
    // Repeated submissions with the same username (which collide on the unique
    // index — 422 after the first) still tick the auth limiter bucket.
    User::factory()->create(['username' => 'repeat']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/register', [
            'username' => 'repeat',
            'email' => "r{$i}@example.com",
            'password' => 'a-strong-pass-123!',
            'password_confirmation' => 'a-strong-pass-123!',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/register', [
        'username' => 'repeat',
        'email' => 'r6@example.com',
        'password' => 'a-strong-pass-123!',
        'password_confirmation' => 'a-strong-pass-123!',
    ])->assertStatus(429);
});
