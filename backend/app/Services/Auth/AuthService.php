<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\WeakPasswordException;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use ZxcvbnPhp\Zxcvbn;

class AuthService
{
    public function __construct(
        private Hasher $hasher,
        private Zxcvbn $zxcvbn,
    ) {}

    public function register(string $username, string $email, string $password): User
    {
        $score = $this->zxcvbn->passwordStrength($password)['score'] ?? 0;
        if ($score < 2) {
            throw new WeakPasswordException('Password is too weak.');
        }

        return User::create([
            'username' => $username,
            'email' => $email,
            'password' => $password, // 'hashed' cast handles it
        ]);
    }

    public function verifyCredentials(string $username, string $password): User
    {
        $user = User::where('username', $username)->first();
        if (! $user || ! $user->password || ! $this->hasher->check($password, $user->password)) {
            throw new InvalidCredentialsException('Invalid username or password.');
        }

        return $user;
    }

    public function issueToken(User $user): string
    {
        return $user->createToken('fishbook')->plainTextToken;
    }
}
