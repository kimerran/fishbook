<?php

use App\Exceptions\FalAi\FalAiQuotaException;
use App\Exceptions\FalAi\FalAiTimeoutException;
use App\Services\FalAi\FalAiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('services.fal.api_key', 'secret-XXXX');
    config()->set('services.fal.base_url', 'https://queue.fal.run');
    config()->set('services.fal.model', 'fal-ai/flux-2/turbo');
    config()->set('services.fal.poll_interval_ms', 1);
    config()->set('services.fal.poll_max_seconds', 1);
    Storage::fake('s3');
});

it('submits, polls until COMPLETED, fetches and stores the webp', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response([
            'request_id' => 'req-1',
            'status_url' => 'https://queue.fal.run/req-1',
            'response_url' => 'https://queue.fal.run/req-1/result',
        ], 200),
        'queue.fal.run/req-1' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-1/result' => Http::response([
            'images' => [['url' => 'https://cdn.fal.test/image-1.png', 'width' => 1920, 'height' => 1080]],
        ], 200),
        'cdn.fal.test/image-1.png' => Http::response(
            file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')),
            200,
            ['content-type' => 'image/jpeg']
        ),
    ]);

    $r = app(FalAiClient::class)->generateBackground('a calm coral reef', '16:9', 42);

    expect($r['storage_key'])->toStartWith('backgrounds/u42/')->toEndWith('.webp');
    Storage::disk('s3')->assertExists($r['storage_key']);
});

it('retries on 5xx on submit and then succeeds', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push([
                'request_id' => 'req-2',
                'status_url' => 'https://queue.fal.run/req-2',
                'response_url' => 'https://queue.fal.run/req-2/result',
            ], 200),
        'queue.fal.run/req-2' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-2/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/x.png']]], 200),
        'cdn.fal.test/x.png' => Http::response(
            file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')),
            200,
            ['content-type' => 'image/jpeg']
        ),
    ]);

    $r = app(FalAiClient::class)->generateBackground('p calm reef', '16:9', 1);
    expect($r['storage_key'])->toContain('backgrounds/u1/');
});

it('throws on timeout when polls never complete', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response([
            'request_id' => 'req-3',
            'status_url' => 'https://queue.fal.run/req-3',
            'response_url' => 'https://queue.fal.run/req-3/result',
        ], 200),
        'queue.fal.run/req-3' => Http::response(['status' => 'IN_PROGRESS'], 200),
    ]);
    expect(fn () => app(FalAiClient::class)->generateBackground('a calm reef', '16:9', 1))
        ->toThrow(FalAiTimeoutException::class);
});

it('throws on 429', function () {
    Http::fake(['queue.fal.run/fal-ai/flux-2/turbo' => Http::response(['error' => 'rate'], 429)]);
    expect(fn () => app(FalAiClient::class)->generateBackground('a calm reef', '16:9', 1))
        ->toThrow(FalAiQuotaException::class);
});

it('never logs FAL_API_KEY', function () {
    $records = [];
    Log::listen(function ($entry) use (&$records) {
        $records[] = json_encode($entry->context).' '.$entry->message;
    });
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response([
            'request_id' => 'req-4',
            'status_url' => 'https://queue.fal.run/req-4',
            'response_url' => 'https://queue.fal.run/req-4/result',
        ], 200),
        'queue.fal.run/req-4' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-4/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/y.png']]], 200),
        'cdn.fal.test/y.png' => Http::response(
            file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')),
            200,
            ['content-type' => 'image/jpeg']
        ),
    ]);
    app(FalAiClient::class)->generateBackground('a calm reef', '16:9', 1);
    expect(collect($records)->some(fn ($r) => str_contains($r, 'secret-XXXX')))->toBeFalse();
});
