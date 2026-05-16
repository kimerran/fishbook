<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    private const DENYLIST = ['password', 'admin', 'changeme', '12345678'];

    public function run(): void
    {
        $password = (string) config('admin.seed_password', '');

        if (app()->environment('production')) {
            if ($password === '' || strlen($password) < 12 || in_array(strtolower($password), self::DENYLIST, true)) {
                throw new RuntimeException(
                    'ADMIN_SEED_PASSWORD is missing, too short, or in the denylist. Refusing to seed.'
                );
            }
        }

        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@fishbook.local',
                'password' => $password !== '' ? $password : 'placeholder-dev-only-12',
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
