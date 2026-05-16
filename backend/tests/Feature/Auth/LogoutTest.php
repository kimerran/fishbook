<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('returns 401 when unauthed', function () {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

it('revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(204);

    // The auth manager caches the resolved guard for the test app instance;
    // forget it so the next request re-resolves Sanctum against the (now
    // revoked) token.
    Auth::forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});
