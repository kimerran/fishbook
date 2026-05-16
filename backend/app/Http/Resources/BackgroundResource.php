<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackgroundResource',
    type: 'object',
    required: ['id', 'kind', 'storage_key', 'signed_url', 'width', 'height', 'is_active', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['upload', 'generated', 'preset']),
        new OA\Property(property: 'storage_key', type: 'string'),
        new OA\Property(property: 'signed_url', type: 'string', format: 'uri'),
        new OA\Property(property: 'width', type: 'integer'),
        new OA\Property(property: 'height', type: 'integer'),
        new OA\Property(property: 'prompt', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class BackgroundResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'kind' => $this->kind,
            'storage_key' => $this->storage_key,
            'signed_url' => Storage::disk('s3')->temporaryUrl($this->storage_key, now()->addHour()),
            'width' => (int) $this->width,
            'height' => (int) $this->height,
            'prompt' => $this->prompt,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
