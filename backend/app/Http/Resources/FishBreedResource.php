<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FishBreedResource',
    type: 'object',
    required: ['id', 'label', 'min_size', 'max_size', 'default_color', 'sprite_key'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'min_size', type: 'integer'),
        new OA\Property(property: 'max_size', type: 'integer'),
        new OA\Property(property: 'default_color', type: 'string'),
        new OA\Property(property: 'sprite_key', type: 'string'),
        new OA\Property(property: 'vertical_band_preference', type: 'string', enum: ['bottom'], nullable: true),
    ],
)]
class FishBreedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $b */
        $b = (array) $this->resource;
        return [
            'id'                       => $b['id'],
            'label'                    => $b['label'],
            'min_size'                 => $b['min_size'],
            'max_size'                 => $b['max_size'],
            'default_color'            => $b['default_color'],
            'sprite_key'               => $b['sprite_key'],
            'vertical_band_preference' => $b['vertical_band_preference'] ?? null,
        ];
    }
}
