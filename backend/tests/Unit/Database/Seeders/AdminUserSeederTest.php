<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;

it('seeds an admin in non-production', function () {
    config(['admin.seed_password' => 'whatever-strong-12chars']);
    (new AdminUserSeeder)->run();
    expect(User::where('username', 'admin')->where('is_admin', true)->exists())->toBeTrue();
});

it('throws in production when password is empty', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['admin.seed_password' => '']);
    expect(fn () => (new AdminUserSeeder)->run())->toThrow(RuntimeException::class);
});

it('throws in production for too-short password', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['admin.seed_password' => 'short']);
    expect(fn () => (new AdminUserSeeder)->run())->toThrow(RuntimeException::class);
});

it('throws in production for denylisted password', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['admin.seed_password' => 'password']);
    expect(fn () => (new AdminUserSeeder)->run())->toThrow(RuntimeException::class);
});

it('succeeds in production with a strong password', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['admin.seed_password' => 'CorrectHorseBatteryStaple-9!']);
    (new AdminUserSeeder)->run();
    expect(User::where('username', 'admin')->exists())->toBeTrue();
});
