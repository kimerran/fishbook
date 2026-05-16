<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates a fish with source=manual and the authed user_id', function () {
    $r = $this->postJson('/api/v1/fishes', [
        'nickname' => 'Blubsworth',
        'breed' => 'guppy',
        'color_hex' => '#FF6B9D',
        'size' => 12,
        // attempted mass-assignment — must be ignored:
        'user_id' => 99999,
        'source' => 'github_repo',
        'source_ref' => 'evil/repo',
    ]);
    $r->assertCreated()
      ->assertJsonPath('data.nickname', 'Blubsworth')
      ->assertJsonPath('data.source', 'manual')
      ->assertJsonPath('data.source_ref', null);

    expect(\App\Models\Fish::first()->user_id)->toBe($this->user->id);
});

it('rejects unknown breed', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Sharky', 'breed' => 'great_white', 'color_hex' => '#000000', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('breed');
});

it('rejects size out of breed range', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Tiny', 'breed' => 'guppy', 'color_hex' => '#FF6B9D', 'size' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('size');
});

it('rejects invalid color hex', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Bad', 'breed' => 'guppy', 'color_hex' => 'red', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('color_hex');
});

it('rejects empty nickname after trim', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => '   ', 'breed' => 'guppy', 'color_hex' => '#FF6B9D', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('nickname');
});
