<?php

use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('s3');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('rejects unauthed', function () {
    auth()->forgetGuards();
    $this->getJson('/api/v1/backgrounds')->assertStatus(401);
});

it('lists only own rows ordered by active+created', function () {
    Background::factory()->for($this->user)->create(['is_active' => true, 'created_at' => now()->subDay()]);
    Background::factory()->for($this->user)->create(['created_at' => now()]);
    Background::factory()->create(); // other user
    $r = $this->getJson('/api/v1/backgrounds')->assertOk();
    expect(count($r->json('data')))->toBe(2);
    expect($r->json('data.0.is_active'))->toBeTrue();
});

it('includes a signed proxy URL pointing at the image route', function () {
    $bg = Background::factory()->for($this->user)->create();
    $r = $this->getJson('/api/v1/backgrounds')->assertOk();
    $url = $r->json('data.0.signed_url');
    expect($url)->toContain("/api/v1/backgrounds/{$bg->ulid}/image");
    expect($url)->toContain('signature=');
    expect($url)->toContain('expires=');
});

it('runs under the query budget', function () {
    Background::factory()->count(20)->for($this->user)->create();
    DB::enableQueryLog();
    $this->getJson('/api/v1/backgrounds')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});
