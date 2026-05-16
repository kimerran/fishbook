<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\WeakPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ClaimUsernameRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    #[OA\Post(
        path: '/auth/register',
        operationId: 'authRegister',
        summary: 'Register a new local user',
        tags: ['auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthTokenResponse'),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Rate limited'),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $user = $this->auth->register($data['username'], $data['email'], $data['password']);
        } catch (WeakPasswordException $e) {
            throw ValidationException::withMessages(['password' => [$e->getMessage()]]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->auth->issueToken($user),
        ], 201);
    }

    #[OA\Post(
        path: '/auth/login',
        operationId: 'authLogin',
        summary: 'Log in with username and password',
        tags: ['auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthTokenResponse'),
            ),
            new OA\Response(response: 422, description: 'Invalid credentials'),
            new OA\Response(response: 429, description: 'Rate limited'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $user = $this->auth->verifyCredentials($data['username'], $data['password']);
        } catch (InvalidCredentialsException) {
            throw ValidationException::withMessages(['credentials' => ['Invalid username or password.']]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->auth->issueToken($user),
        ]);
    }

    #[OA\Post(
        path: '/auth/logout',
        operationId: 'authLogout',
        summary: 'Revoke the current Sanctum token',
        tags: ['auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'No Content'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function logout(Request $request): Response
    {
        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $request->user()->currentAccessToken();
        // Only revoke real personal access tokens; TransientToken (session
        // stateful) has no underlying row to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    #[OA\Get(
        path: '/auth/me',
        operationId: 'authMe',
        summary: 'Get the authenticated user',
        tags: ['auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MeResponse'),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    #[OA\Post(
        path: '/auth/claim-username',
        operationId: 'authClaimUsername',
        summary: 'Set username for an OAuth-created user',
        tags: ['auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ClaimUsernameRequest')),
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MeResponse'),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function claimUsername(ClaimUsernameRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->username = $request->validated('username');
        $user->save();

        return response()->json(['user' => new UserResource($user)]);
    }
}
