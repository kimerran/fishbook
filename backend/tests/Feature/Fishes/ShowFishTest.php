<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns own fish', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $this->getJson("/api/v1/fishes/{$fish->ulid}")->assertOk()->assertJsonPath('data.id', $fish->ulid);
});

it('forbids viewing another user’s fish', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);
    $other = Fish::factory()->create();
    $this->getJson("/api/v1/fishes/{$other->ulid}")->assertStatus(403);
});

it('returns 404 for soft-deleted fish', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $fish->delete();
    $this->getJson("/api/v1/fishes/{$fish->ulid}")->assertStatus(404);
});
