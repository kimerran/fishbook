<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config()->set('services.fal.api_key', 'secret');
    config()->set('services.fal.poll_interval_ms', 1);
    config()->set('services.fal.poll_max_seconds', 1);
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, $expiration) {
        return 'https://fake-s3.test/'.$path.'?X-Amz-Signature=stub&X-Amz-Expires=3600';
    });
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response([
            'request_id' => 'r',
            'status_url' => 'https://queue.fal.run/r',
            'response_url' => 'https://queue.fal.run/r/result',
        ], 200),
        'queue.fal.run/r' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/r/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/i.png']]], 200),
        'cdn.fal.test/i.png' => Http::response(
            file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')),
            200,
            ['content-type' => 'image/jpeg']
        ),
    ]);
});

it('generates a background', function () {
    $r = $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'a calm reef', 'aspect_ratio' => '16:9']);
    $r->assertCreated()
        ->assertJsonPath('data.kind', 'generated')
        ->assertJsonPath('data.prompt', 'a calm reef');
});

it('rejects a denylisted prompt', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'nsfw scene please', 'aspect_ratio' => '16:9'])
        ->assertStatus(422);
});

it('rejects a too-short prompt', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'no', 'aspect_ratio' => '16:9'])
        ->assertStatus(422);
});

it('rejects a too-long prompt', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => str_repeat('a', 501), 'aspect_ratio' => '16:9'])
        ->assertStatus(422);
});

it('rejects an invalid aspect ratio', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'a calm reef', 'aspect_ratio' => '4:3'])
        ->assertStatus(422);
});

it('throttles after 10/hr per user', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/backgrounds/generate', ['prompt' => "calm scene $i", 'aspect_ratio' => '16:9']);
    }
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'one too many', 'aspect_ratio' => '16:9'])
        ->assertStatus(429);
});
