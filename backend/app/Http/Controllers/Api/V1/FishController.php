<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fish\StoreFishRequest;
use App\Http\Requests\Fish\UpdateFishRequest;
use App\Http\Resources\FishBreedResource;
use App\Http\Resources\FishResource;
use App\Models\Fish;
use App\Services\Fish\BreedCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class FishController extends Controller
{
    public function __construct(private readonly BreedCatalog $breeds) {}

    #[OA\Get(
        path: '/fishes',
        operationId: 'listFishes',
        tags: ['Fishes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'breed', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'color', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['name', 'breed', 'created_at', 'size'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/PaginatedFishCollection')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Fish::class);

        $q = Fish::forUser($request->user()->id);

        if ($s = $request->string('search')->toString()) {
            $q->where('nickname', 'ilike', '%'.$s.'%');
        }
        if ($b = $request->string('breed')->toString()) {
            $q->where('breed', $b);
        }
        if ($c = $request->string('color')->toString()) {
            $q->where('color_hex', $c);
        }

        $sort = $request->string('sort', 'created_at')->toString();
        $sortMap = ['name' => 'nickname', 'breed' => 'breed', 'created_at' => 'created_at', 'size' => 'size'];
        $sortCol = $sortMap[$sort] ?? 'created_at';
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortCol, $direction);

        $perPage = (int) min($request->integer('per_page', 25), 100);

        return FishResource::collection($q->paginate($perPage));
    }

    #[OA\Post(
        path: '/fishes',
        operationId: 'createFish',
        tags: ['Fishes'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreFishRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/FishResourceEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreFishRequest $request): JsonResponse
    {
        $this->authorize('create', Fish::class);

        $fish = Fish::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'source' => 'manual',
        ]);

        return (new FishResource($fish))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/fishes/{fish}',
        operationId: 'getFish',
        tags: ['Fishes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'fish', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$'))],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/FishResourceEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Fish $fish): FishResource
    {
        $this->authorize('view', $fish);

        return new FishResource($fish);
    }

    #[OA\Patch(
        path: '/fishes/{fish}',
        operationId: 'updateFish',
        tags: ['Fishes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'fish', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateFishRequest')),
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/FishResourceEnvelope')),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateFishRequest $request, Fish $fish): FishResource
    {
        $this->authorize('update', $fish);

        $fish->update($request->validated());

        return new FishResource($fish->fresh());
    }

    #[OA\Delete(
        path: '/fishes/{fish}',
        operationId: 'deleteFish',
        tags: ['Fishes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'fish', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'ulid', pattern: '^[0-9A-HJKMNP-TV-Z]{26}$'))],
        responses: [
            new OA\Response(response: 204, description: 'No content'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Fish $fish): JsonResponse
    {
        $this->authorize('delete', $fish);

        $fish->delete();

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/fishes/breeds',
        operationId: 'listBreeds',
        tags: ['Fishes'],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/FishBreedCollection')),
        ],
    )]
    public function breeds(Request $request): JsonResponse
    {
        return FishBreedResource::collection($this->breeds->all())
            ->response($request)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
