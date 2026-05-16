<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserResource',
    type: 'object',
    required: ['id', 'username', 'email', 'is_admin', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'username', type: 'string', example: 'kimerran'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'is_admin', type: 'boolean', example: false),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'AuthTokenResponse',
    type: 'object',
    required: ['user', 'token'],
    properties: [
        new OA\Property(property: 'user', ref: '#/components/schemas/UserResource'),
        new OA\Property(property: 'token', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'MeResponse',
    type: 'object',
    required: ['user'],
    properties: [
        new OA\Property(property: 'user', ref: '#/components/schemas/UserResource'),
    ],
)]
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
