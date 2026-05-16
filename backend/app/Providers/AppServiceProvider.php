<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use ZxcvbnPhp\Zxcvbn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Zxcvbn::class);

        $this->app->singleton(ImageManager::class, fn () => new ImageManager(new GdDriver));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $username = (string) $request->input('username', 'anon');

            return Limit::perMinute(5)->by($request->ip().':'.$username)
                ->response(function () {
                    return response()->json(
                        ['message' => 'Too many attempts. Try again in a minute.'],
                        429,
                    );
                });
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                optional($request->user())->id ?: $request->ip()
            );
        });

        RateLimiter::for('generate', function (Request $request) {
            $userId = optional($request->user())->id;
            $dailyGlobal = (int) config('services.fal.daily_global_limit', 200);

            return [
                Limit::perHour(10)->by("generate-u:{$userId}")
                    ->response(fn () => response()->json(['message' => 'Generation rate limit reached. Try again later.'], 429)),
                Limit::perDay($dailyGlobal)->by('generate-global')
                    ->response(fn () => response()->json(['message' => 'Daily generation ceiling reached.'], 503, ['Retry-After' => '3600'])),
            ];
        });
    }
}
