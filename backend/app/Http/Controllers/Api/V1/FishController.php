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

class FishController extends Controller
{
    public function __construct(private readonly BreedCatalog $breeds) {}

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

    public function store(StoreFishRequest $request): JsonResponse
    {
        $this->authorize('create', Fish::class);

        $fish = Fish::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'source'  => 'manual',
        ]);

        return (new FishResource($fish))->response()->setStatusCode(201);
    }

    public function show(Fish $fish): FishResource
    {
        $this->authorize('view', $fish);

        return new FishResource($fish);
    }

    public function update(UpdateFishRequest $request, Fish $fish): FishResource
    {
        $this->authorize('update', $fish);

        $fish->update($request->validated());

        return new FishResource($fish->fresh());
    }

    public function destroy(Fish $fish): JsonResponse
    {
        $this->authorize('delete', $fish);

        $fish->delete();

        return response()->json(null, 204);
    }

    /** Public endpoint — see routes/api.php. */
    public function breeds(): AnonymousResourceCollection
    {
        return FishBreedResource::collection($this->breeds->all());
    }
}
