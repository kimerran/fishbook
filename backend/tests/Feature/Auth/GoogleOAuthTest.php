<?php

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery as M;

afterEach(fn () => M::close());

it('404s on /auth/google/redirect when feature flag is off', function () {
    config(['services.google_oauth_enabled' => false]);
    $this->get('/api/v1/auth/google/redirect')->assertStatus(404);
});

it('302s to Google when flag is on', function () {
    config(['services.google_oauth_enabled' => true]);
    config(['services.google.client_id' => 'fake-id']);
    config(['services.google.client_secret' => 'fake-secret']);
    config(['services.google.redirect' => 'http://localhost:8000/api/v1/auth/google/callback']);
    $this->get('/api/v1/auth/google/redirect')->assertRedirect();
});

it('creates a new user on first callback', function () {
    config(['services.google_oauth_enabled' => true]);
    $sUser = M::mock(SocialiteUser::class);
    $sUser->shouldReceive('getId')->andReturn('99999');
    $sUser->shouldReceive('getEmail')->andReturn('new@google.test');
    $sUser->shouldReceive('getName')->andReturn('New User');
    Socialite::shouldReceive('driver->stateless->user')->andReturn($sUser);

    $this->get('/api/v1/auth/google/callback?code=abc')->assertRedirect();

    expect(User::where('google_id', '99999')->exists())->toBeTrue();
});

it('matches an existing user by google_id', function () {
    config(['services.google_oauth_enabled' => true]);
    $existing = User::factory()->googleOnly()->create(['google_id' => '12345']);

    $sUser = M::mock(SocialiteUser::class);
    $sUser->shouldReceive('getId')->andReturn('12345');
    $sUser->shouldReceive('getEmail')->andReturn('whatever@google.test');
    $sUser->shouldReceive('getName')->andReturn('Existing');
    Socialite::shouldReceive('driver->stateless->user')->andReturn($sUser);

    $this->get('/api/v1/auth/google/callback?code=abc')->assertRedirect();

    expect(User::count())->toBe(1)
        ->and(User::find($existing->id)->google_id)->toBe('12345');
});

it('matches an existing verified-email user when no google_id matches', function () {
    config(['services.google_oauth_enabled' => true]);
    $existing = User::factory()->create([
        'email' => 'verified@example.com',
        'email_verified_at' => now(),
        'google_id' => null,
    ]);

    $sUser = M::mock(SocialiteUser::class);
    $sUser->shouldReceive('getId')->andReturn('77777');
    $sUser->shouldReceive('getEmail')->andReturn('verified@example.com');
    $sUser->shouldReceive('getName')->andReturn('Linked');
    Socialite::shouldReceive('driver->stateless->user')->andReturn($sUser);

    $this->get('/api/v1/auth/google/callback?code=abc')->assertRedirect();

    expect(User::find($existing->id)->google_id)->toBe('77777');
});
