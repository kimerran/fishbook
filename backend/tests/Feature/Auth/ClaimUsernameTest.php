<?php

use App\Models\User;

it('rejects unauthed callers', function () {
    $this->postJson('/api/v1/auth/claim-username', ['username' => 'whatever'])->assertStatus(401);
});

it('updates the username', function () {
    $user = User::factory()->create(['username' => 'auto_abcd']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/claim-username', ['username' => 'kimerran'])
        ->assertOk()
        ->assertJson(['user' => ['username' => 'kimerran']]);
});

it('rejects an invalid regex', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/claim-username', ['username' => 'bad name!'])
        ->assertStatus(422);
});

it('rejects a colliding username (case-insensitive citext)', function () {
    User::factory()->create(['username' => 'TakenName']);
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/claim-username', ['username' => 'takenname'])
        ->assertStatus(422);
});
