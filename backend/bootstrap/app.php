<?php

use App\Exceptions\Backgrounds\DimensionsTooSmallException;
use App\Exceptions\Backgrounds\DisallowedPromptException;
use App\Exceptions\Backgrounds\FileTooLargeException;
use App\Exceptions\Backgrounds\InvalidImageException;
use App\Exceptions\FalAi\FalAiFailedException;
use App\Exceptions\FalAi\FalAiQuotaException;
use App\Exceptions\FalAi\FalAiTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DimensionsTooSmallException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
        });
        $exceptions->render(function (FileTooLargeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
        });
        $exceptions->render(function (InvalidImageException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
        });
        $exceptions->render(function (DisallowedPromptException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['prompt' => [$e->getMessage()]]], 422);
        });
        $exceptions->render(function (FalAiTimeoutException $e) {
            return response()->json(['message' => $e->getMessage()], 504);
        });
        $exceptions->render(function (FalAiFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        });
        $exceptions->render(function (FalAiQuotaException $e) {
            return response()->json(['message' => $e->getMessage()], 429, ['Retry-After' => '3600']);
        });
        $exceptions->render(function (QueryException $e) {
            if (str_contains($e->getMessage(), 'one_active_bg_per_user')) {
                return response()->json(['message' => 'Another select is in progress; retry.'], 409);
            }

            return null;
        });
    })->create();
