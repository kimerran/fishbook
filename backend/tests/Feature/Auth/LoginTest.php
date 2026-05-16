<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => RateLimiter::clear('auth'));

it('logs in with correct credentials', function () {
    User::factory()->create(['username' => 'alice', 'password' => bcrypt('right-pass-12')]);

    $this->postJson('/api/v1/auth/login', ['username' => 'alice', 'password' => 'right-pass-12'])
        ->assertOk()
        ->assertJsonStructure(['user', 'token']);
});

it('returns the same generic error for wrong password and unknown user', function () {
    User::factory()->create(['username' => 'bob', 'password' => bcrypt('right-pass-12')]);

    $wrong = $this->postJson('/api/v1/auth/login', ['username' => 'bob', 'password' => 'WRONG-pass-12']);
    $unknown = $this->postJson('/api/v1/auth/login', ['username' => 'ghost', 'password' => 'whatever-12']);

    $wrong->assertStatus(422)->assertJson(['errors' => ['credentials' => ['Invalid username or password.']]]);
    $unknown->assertStatus(422)->assertJson(['errors' => ['credentials' => ['Invalid username or password.']]]);
});

it('rate-limits login at 5 per minute per (ip, username)', function () {
    User::factory()->create(['username' => 'cap', 'password' => bcrypt('right-pass-12')]);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['username' => 'cap', 'password' => 'wrong'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['username' => 'cap', 'password' => 'wrong'])->assertStatus(429);
});
