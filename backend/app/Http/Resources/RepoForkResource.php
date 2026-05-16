<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RepoForkResponse',
    type: 'object',
    properties: [new OA\Property(property: 'added', type: 'integer')],
)]
class RepoForkResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{added: int} $payload */
        $payload = $this->resource;

        return ['added' => (int) $payload['added']];
    }
}
