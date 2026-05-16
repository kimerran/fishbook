<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackgroundController;
use App\Http\Controllers\Api\V1\FishController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

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

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('fishes', FishController::class)
        ->where(['fish' => '[0-9]+']);

    Route::get('/backgrounds', [BackgroundController::class, 'index'])
        ->middleware('throttle:api')->name('backgrounds.index');
    Route::post('/backgrounds/upload', [BackgroundController::class, 'upload'])
        ->middleware('throttle:api')->name('backgrounds.upload');
    Route::post('/backgrounds/generate', [BackgroundController::class, 'generate'])
        ->middleware('throttle:generate')->name('backgrounds.generate');
    Route::patch('/backgrounds/{background}/select', [BackgroundController::class, 'select'])
        ->middleware('throttle:api')->name('backgrounds.select')->where(['background' => '[0-9]+']);
    Route::delete('/backgrounds/{background}', [BackgroundController::class, 'destroy'])
        ->middleware('throttle:api')->name('backgrounds.destroy')->where(['background' => '[0-9]+']);
});
