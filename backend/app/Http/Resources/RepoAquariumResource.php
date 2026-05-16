<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RepoStats',
    type: 'object',
    properties: [
        new OA\Property(property: 'stars', type: 'integer'),
        new OA\Property(property: 'forks', type: 'integer'),
        new OA\Property(property: 'issues', type: 'integer'),
        new OA\Property(property: 'watchers', type: 'integer'),
        new OA\Property(property: 'contributors', type: 'integer'),
        new OA\Property(property: 'language', type: 'string', nullable: true),
        new OA\Property(property: 'age_days', type: 'integer'),
        new OA\Property(property: 'fetched_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'RepoFishItem',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'breed', type: 'string'),
        new OA\Property(property: 'color_hex', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'nickname', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'source_ref', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'RepoAquariumResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
            new OA\Property(property: 'stats', ref: '#/components/schemas/RepoStats'),
            new OA\Property(
                property: 'fish_set',
                type: 'array',
                items: new OA\Items(ref: '#/components/schemas/RepoFishItem'),
            ),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'RepoAquariumErrorResponse',
    type: 'object',
    properties: [new OA\Property(property: 'message', type: 'string')],
)]
class RepoAquariumResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{stats: array<string, mixed>, fish_set: array<int, array<string, mixed>>} $payload */
        $payload = $this->resource;

        return ['data' => [
            'stats' => $payload['stats'],
            'fish_set' => $payload['fish_set'],
        ]];
    }
}
