<?php

use App\Models\User;

it('returns 401 without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('returns the authenticated user', function () {
    $user = User::factory()->create(['username' => 'me']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJson(['user' => ['username' => 'me']]);
});
