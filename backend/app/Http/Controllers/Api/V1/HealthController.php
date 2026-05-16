<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'Fishbook API')]
#[OA\Server(url: '/api/v1')]
#[OA\Schema(
    schema: 'HealthResponse',
    type: 'object',
    required: ['ok', 'version'],
    properties: [
        new OA\Property(property: 'ok', type: 'boolean', example: true),
        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
    ],
)]
class HealthController extends Controller
{
    /**
     * @return array{ok: bool, version: string}
     */
    #[OA\Get(
        path: '/health',
        operationId: 'getHealth',
        summary: 'Service liveness probe',
        tags: ['meta'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse'),
            ),
        ],
    )]
    public function __invoke(): array
    {
        return [
            'ok' => true,
            'version' => config('app.version', '0.0.0'),
        ];
    }
}
