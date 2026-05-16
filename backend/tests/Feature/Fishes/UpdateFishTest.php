<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->fish = Fish::factory()->for($this->user)->create(['breed' => 'guppy', 'size' => 12]);
});

it('updates nickname / color / size', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", [
        'nickname' => 'Newname', 'color_hex' => '#123456', 'size' => 15,
    ])->assertOk()->assertJsonPath('data.nickname', 'Newname');
});

it('rejects breed change (immutable)', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", ['breed' => 'molly'])
        ->assertStatus(422)->assertJsonValidationErrors('breed');
});

it('rejects size outside the breed range', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", ['size' => 99])
        ->assertStatus(422)->assertJsonValidationErrors('size');
});

it('forbids updating another user’s fish', function () {
    $other = Fish::factory()->create();
    $this->patchJson("/api/v1/fishes/{$other->id}", ['nickname' => 'pwn'])->assertStatus(403);
});
