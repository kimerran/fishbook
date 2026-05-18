<?php

namespace App\Http\Resources;

use App\Models\Background;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackgroundResource',
    type: 'object',
    required: ['id', 'kind', 'storage_key', 'signed_url', 'width', 'height', 'is_active', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$', example: '01HG5W7DZ8KX9F3N2VYRMPQABT'),
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
        /** @var Background $bg */
        $bg = $this->resource;

        return [
            'id' => $bg->ulid,
            'kind' => $bg->kind,
            'storage_key' => $bg->storage_key,
            'signed_url' => URL::temporarySignedRoute(
                'backgrounds.image',
                now()->addHour(),
                ['background' => $bg->ulid],
            ),
            'width' => (int) $bg->width,
            'height' => (int) $bg->height,
            'prompt' => $bg->prompt,
            'is_active' => (bool) $bg->is_active,
            'created_at' => $bg->created_at?->toIso8601String(),
        ];
    }
}
