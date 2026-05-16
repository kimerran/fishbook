<?php

use App\Models\Background;
use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('emits ulid as id on fish list', function () {
    $user = User::factory()->create();
    Fish::factory()->count(2)->for($user)->create();
    Sanctum::actingAs($user);

    $r = $this->getJson('/api/v1/fishes')->assertOk();

    foreach ($r->json('data') as $row) {
        expect($row['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
});

it('resolves a fish by ulid', function () {
    $user = User::factory()->create();
    $fish = Fish::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/fishes/{$fish->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $fish->ulid);
});

it('404s on integer id (route pattern miss)', function () {
    $user = User::factory()->create();
    Fish::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/fishes/123')->assertNotFound();
});

it('emits ulid as id on backgrounds list', function () {
    $user = User::factory()->create();
    Background::factory()->count(2)->for($user)->create();
    Sanctum::actingAs($user);

    $r = $this->getJson('/api/v1/backgrounds')->assertOk();

    foreach ($r->json('data') as $row) {
        expect($row['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
});
