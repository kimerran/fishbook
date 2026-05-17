<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backgrounds\GenerateBackgroundRequest;
use App\Http\Requests\Backgrounds\SelectBackgroundRequest;
use App\Http\Requests\Backgrounds\UploadBackgroundRequest;
use App\Http\Resources\BackgroundResource;
use App\Models\Background;
use App\Services\Backgrounds\BackgroundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class BackgroundController extends Controller
{
    public function __construct(private readonly BackgroundService $service) {}

    #[OA\Get(
        path: '/backgrounds',
        operationId: 'listBackgrounds',
        tags: ['Backgrounds'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/BackgroundCollection')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Background::class);

        $perPage = (int) min($request->integer('per_page', 25), 50);
        $page = Background::query()->forUser($request->user()->id)
            ->orderByDesc('is_active')->orderByDesc('created_at')
            ->paginate($perPage);

        return BackgroundResource::collection($page);
    }

    #[OA\Post(
        path: '/backgrounds/upload',
        operationId: 'uploadBackground',
        tags: ['Backgrounds'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/UploadBackgroundRequest'),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/BackgroundResourceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function upload(UploadBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->upload($request->user(), $request->file('image'));

        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/backgrounds/generate',
        operationId: 'generateBackground',
        tags: ['Backgrounds'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/GenerateBackgroundRequest'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/BackgroundResourceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 429, description: 'Rate limited'),
            new OA\Response(response: 503, description: 'Daily global ceiling exhausted'),
        ],
    )]
    public function generate(GenerateBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->generate(
            $request->user(),
            (string) $request->input('prompt'),
            (string) $request->input('aspect_ratio', '16:9'),
        );

        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/backgrounds/{background}/select',
        operationId: 'selectBackground',
        tags: ['Backgrounds'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'background', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/BackgroundResourceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Concurrent select race'),
        ],
    )]
    public function select(SelectBackgroundRequest $request, Background $background): BackgroundResource
    {
        // SelectBackgroundRequest's authorize() already enforces the policy.
        return new BackgroundResource($this->service->select($request->user(), $background));
    }

    #[OA\Delete(
        path: '/backgrounds/{background}',
        operationId: 'deleteBackground',
        tags: ['Backgrounds'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'background', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Soft-deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Request $request, Background $background): JsonResponse
    {
        $this->authorize('delete', $background);
        $this->service->delete($request->user(), $background);

        return response()->json(null, 204);
    }
}
