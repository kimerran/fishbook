<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('soft-deletes and disappears from index but stays in DB', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();

    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertNoContent();

    $this->getJson('/api/v1/fishes')->assertOk()->assertJsonCount(0, 'data');

    expect(DB::table('fishes')->where('id', $fish->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('forbids deleting another user’s fish', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);
    $other = Fish::factory()->create();
    $this->deleteJson("/api/v1/fishes/{$other->id}")->assertStatus(403);
});

it('returns 404 on second delete (idempotency boundary)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertNoContent();
    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertStatus(404);
});
