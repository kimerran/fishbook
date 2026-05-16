<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginatedFishCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FishResource')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'FishBreedCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FishBreedResource')),
    ],
)]
#[OA\Schema(
    schema: 'FishResourceEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/FishResource'),
    ],
)]
#[OA\Schema(
    schema: 'BackgroundCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BackgroundResource')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'BackgroundResourceEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/BackgroundResource'),
    ],
)]
class Schemas {}
