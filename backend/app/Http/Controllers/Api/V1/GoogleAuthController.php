<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class GoogleAuthController extends Controller
{
    public function __construct(
        private GoogleOAuthService $google,
        private AuthService $auth,
    ) {}

    #[OA\Get(
        path: '/auth/google/redirect',
        operationId: 'authGoogleRedirect',
        summary: 'Redirect to Google OAuth consent',
        tags: ['auth'],
        responses: [
            new OA\Response(response: 302, description: 'Redirect'),
            new OA\Response(response: 404, description: 'OAuth disabled'),
        ],
    )]
    public function redirect(): Response
    {
        abort_unless(config('services.google_oauth_enabled'), 404);

        /** @phpstan-ignore-next-line method.notFound — stateless() exists on Two\AbstractProvider */
        return Socialite::driver('google')->stateless()->redirect();
    }

    #[OA\Get(
        path: '/auth/google/callback',
        operationId: 'authGoogleCallback',
        summary: 'Receive Google OAuth callback and bootstrap a session',
        tags: ['auth'],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to frontend finish page'),
            new OA\Response(response: 404, description: 'OAuth disabled'),
        ],
    )]
    public function callback(): RedirectResponse
    {
        abort_unless(config('services.google_oauth_enabled'), 404);

        /** @phpstan-ignore-next-line method.notFound — stateless() exists on Two\AbstractProvider */
        $googleUser = Socialite::driver('google')->stateless()->user();
        $newUser = ! User::where('google_id', $googleUser->getId())->exists();
        $user = $this->google->resolve($googleUser);
        $token = $this->auth->issueToken($user);

        $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        return redirect()->away(
            "{$frontend}/auth/google/finish?token={$token}&new=".($newUser ? '1' : '0')
        );
    }
}
