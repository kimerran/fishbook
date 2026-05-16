<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Github\GithubUnavailableException;
use App\Exceptions\Github\RepoForbiddenException;
use App\Exceptions\Github\RepoNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepoAquariumResource;
use App\Http\Resources\RepoForkResource;
use App\Services\Github\RepoAquariumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RepoAquariumController extends Controller
{
    public function __construct(private readonly RepoAquariumService $service) {}

    #[OA\Get(
        path: '/api/v1/repos/{owner}/{repo}/aquarium',
        operationId: 'getRepoAquarium',
        tags: ['Repo Aquarium'],
        parameters: [
            new OA\Parameter(name: 'owner', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'repo', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/RepoAquariumResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/RepoAquariumErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/RepoAquariumErrorResponse')),
        ],
    )]
    public function show(string $owner, string $repo): JsonResponse
    {
        if (RepoAquariumService::isReservedRoute($owner)) {
            abort(404);
        }
        try {
            return (new RepoAquariumResource($this->service->getOrGenerate($owner, $repo)))->response();
        } catch (RepoNotFoundException) {
            abort(404);
        } catch (RepoForbiddenException) {
            abort(403, 'Repository is private or rate-limited.');
        } catch (GithubUnavailableException) {
            abort(503, 'GitHub is temporarily unavailable.');
        }
    }

    #[OA\Post(
        path: '/api/v1/repos/{owner}/{repo}/fork-to-my-aquarium',
        operationId: 'forkRepoAquarium',
        tags: ['Repo Aquarium'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'owner', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'repo', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/RepoForkResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function fork(string $owner, string $repo, Request $request): JsonResponse
    {
        if (RepoAquariumService::isReservedRoute($owner)) {
            abort(404);
        }
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        try {
            $result = $this->service->materializeForUser($user, $owner, $repo);

            return (new RepoForkResource($result))->response()->setStatusCode(201);
        } catch (RepoNotFoundException) {
            abort(404);
        } catch (RepoForbiddenException) {
            abort(403);
        }
    }
}
