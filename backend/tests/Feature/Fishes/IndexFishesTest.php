<?php

use App\Models\Fish;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('rejects unauthed', function () {
    auth()->forgetGuards();
    $this->getJson('/api/v1/fishes')->assertStatus(401);
});

it('lists only the authed user’s fish', function () {
    Fish::factory()->count(3)->for($this->user)->create();
    Fish::factory()->count(2)->create();

    $r = $this->getJson('/api/v1/fishes');
    $r->assertOk()->assertJsonCount(3, 'data');
});

it('paginates with capped per_page', function () {
    Fish::factory()->count(120)->for($this->user)->create();
    $r = $this->getJson('/api/v1/fishes?per_page=200');
    expect(count($r->json('data')))->toBe(100);
});

it('filters by breed and color, sorts by name asc', function () {
    Fish::factory()->for($this->user)->create(['nickname' => 'Aaron', 'breed' => 'guppy',  'color_hex' => '#FF6B9D']);
    Fish::factory()->for($this->user)->create(['nickname' => 'Zed',   'breed' => 'guppy',  'color_hex' => '#FF6B9D']);
    Fish::factory()->for($this->user)->create(['nickname' => 'Mel',   'breed' => 'molly',  'color_hex' => '#1F2937']);

    $r = $this->getJson('/api/v1/fishes?breed=guppy&color=%23FF6B9D&sort=name&direction=asc');
    $r->assertOk();
    expect(collect($r->json('data'))->pluck('nickname')->all())->toBe(['Aaron', 'Zed']);
});

it('runs under the query budget (N+1 guardrail)', function () {
    Fish::factory()->count(50)->for($this->user)->create();
    DB::enableQueryLog();
    $this->getJson('/api/v1/fishes')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});
