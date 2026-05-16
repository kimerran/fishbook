<?php

use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-populates ulid on create when absent', function () {
    $user = User::factory()->create();
    $fish = Fish::create([
        'user_id' => $user->id,
        'nickname' => 'Bubbles',
        'breed' => 'guppy',
        'color_hex' => '#FF6B9D',
        'size' => 12,
        'source' => 'manual',
    ]);

    expect($fish->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('respects a supplied ulid', function () {
    $user = User::factory()->create();
    $supplied = '01HZ123456789ABCDEFGHJKMNP';
    $fish = Fish::create([
        'user_id' => $user->id,
        'nickname' => 'Bubbles',
        'breed' => 'guppy',
        'color_hex' => '#FF6B9D',
        'size' => 12,
        'source' => 'manual',
        'ulid' => $supplied,
    ]);

    expect($fish->ulid)->toBe($supplied);
});

it('exposes ulid as the route key name', function () {
    expect((new Fish)->getRouteKeyName())->toBe('ulid');
});
