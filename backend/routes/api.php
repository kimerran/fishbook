<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackgroundController;
use App\Http\Controllers\Api\V1\FishController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\RepoAquariumController;
use Illuminate\Support\Facades\Route;

// Slice 5: repo-aquarium owner/repo path-segment constraints (SPEC §16).
// Misshaped owners/repos miss the route and return 404 — never leak the route table.
Route::pattern('owner', '[A-Za-z0-9._-]{1,100}');
Route::pattern('repo', '[A-Za-z0-9._-]{1,100}');

// Slice 6: fish + background route-bind by ULID (26-char Crockford base32 uppercase).
Route::pattern('fish', '[0-9A-HJKMNP-TV-Z]{26}');
Route::pattern('background', '[0-9A-HJKMNP-TV-Z]{26}');

Route::get('/health', HealthController::class)->name('health');

// Serves the committed OpenAPI spec for /api-docs (swagger-ui-react).
Route::get('/openapi.json', fn () => response()->file(storage_path('api-docs/openapi.json'), [
    'Content-Type' => 'application/json',
    'Cache-Control' => 'public, max-age=300',
]))->name('openapi.json');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/claim-username', [AuthController::class, 'claimUsername']);
    });

    // Google OAuth (gated by env in the controller).
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
});

// Public — order-sensitive: declare before the resource so `breeds` does not bind as a fish id.
Route::get('/fishes/breeds', [FishController::class, 'breeds'])
    ->middleware('throttle:api')
    ->name('fishes.breeds');

// Signed HMAC URL (parity with S3 presign trust model) — emitted by BackgroundResource.signed_url.
Route::get('/backgrounds/{background}/image', [BackgroundController::class, 'image'])
    ->middleware(['signed', 'throttle:api'])
    ->name('backgrounds.image');

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('fishes', FishController::class);

    Route::get('/backgrounds', [BackgroundController::class, 'index'])
        ->middleware('throttle:api')->name('backgrounds.index');
    Route::post('/backgrounds/upload', [BackgroundController::class, 'upload'])
        ->middleware('throttle:api')->name('backgrounds.upload');
    Route::post('/backgrounds/generate', [BackgroundController::class, 'generate'])
        ->middleware('throttle:generate')->name('backgrounds.generate');
    Route::patch('/backgrounds/{background}/select', [BackgroundController::class, 'select'])
        ->middleware('throttle:api')->name('backgrounds.select');
    Route::delete('/backgrounds/{background}', [BackgroundController::class, 'destroy'])
        ->middleware('throttle:api')->name('backgrounds.destroy');
});

// Slice 5: GitHub Repo Aquarium endpoints. Global apiPrefix=api/v1 (bootstrap/app.php) handles versioning.
Route::get('/repos/{owner}/{repo}/aquarium', [RepoAquariumController::class, 'show'])
    ->middleware('throttle:api')
    ->name('repos.aquarium.show');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/repos/{owner}/{repo}/fork-to-my-aquarium', [RepoAquariumController::class, 'fork'])
        ->name('repos.aquarium.fork');
});
