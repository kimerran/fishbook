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

class BackgroundController extends Controller
{
    public function __construct(private readonly BackgroundService $service)
    {
        $this->authorizeResource(Background::class, 'background');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min($request->integer('per_page', 25), 50);
        $page = Background::query()->forUser($request->user()->id)
            ->orderByDesc('is_active')->orderByDesc('created_at')
            ->paginate($perPage);

        return BackgroundResource::collection($page);
    }

    public function upload(UploadBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->upload($request->user(), $request->file('image'));

        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    public function generate(GenerateBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->generate(
            $request->user(),
            (string) $request->input('prompt'),
            (string) $request->input('aspect_ratio', '16:9'),
        );

        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    public function select(SelectBackgroundRequest $request, Background $background): BackgroundResource
    {
        return new BackgroundResource($this->service->select($request->user(), $background));
    }

    public function destroy(Background $background): JsonResponse
    {
        $this->service->delete(request()->user(), $background);

        return response()->json(null, 204);
    }
}
