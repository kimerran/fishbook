<?php

namespace App\Http\Resources;

use App\Models\Fish;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FishResource',
    type: 'object',
    required: ['id', 'nickname', 'breed', 'color_hex', 'size', 'source', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'nickname', type: 'string'),
        new OA\Property(property: 'breed', type: 'string'),
        new OA\Property(property: 'color_hex', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'source', type: 'string', enum: ['manual', 'github_repo']),
        new OA\Property(property: 'source_ref', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class FishResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Fish $fish */
        $fish = $this->resource;

        return [
            'id' => (string) $fish->id,
            'nickname' => $fish->nickname,
            'breed' => $fish->breed,
            'color_hex' => $fish->color_hex,
            'size' => (int) $fish->size,
            'source' => $fish->source,
            'source_ref' => $fish->source_ref,
            'created_at' => $fish->created_at?->toIso8601String(),
            'updated_at' => $fish->updated_at?->toIso8601String(),
        ];
    }
}
