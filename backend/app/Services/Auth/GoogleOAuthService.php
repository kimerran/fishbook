<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GoogleOAuthService
{
    public function resolve(SocialiteUser $g): User
    {
        $user = User::where('google_id', $g->getId())->first();
        if ($user) {
            return $user;
        }

        $user = User::whereNotNull('email_verified_at')
            ->where('email', $g->getEmail())
            ->first();

        if ($user) {
            $user->google_id = $g->getId();
            $user->save();

            return $user;
        }

        return User::create([
            'username' => $this->generateUsername($g->getName() ?? $g->getEmail() ?? 'user'),
            'email' => $g->getEmail(),
            'google_id' => $g->getId(),
            'email_verified_at' => now(),
            'password' => null,
        ]);
    }

    private function generateUsername(string $seed): string
    {
        $base = Str::limit(Str::slug($seed, '_'), 28, '');
        $base = $base !== '' ? $base : 'user';
        do {
            $candidate = $base.'_'.Str::lower(Str::random(4));
        } while (User::where('username', $candidate)->exists());

        return $candidate;
    }
}
