<?php

use App\Models\Background;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, $expiration) {
        return 'https://fake-s3.test/'.$path.'?X-Amz-Signature=stub&X-Amz-Expires=3600';
    });
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('flips prior active to false and selected to true', function () {
    $a = Background::factory()->for($this->user)->create(['is_active' => true]);
    $b = Background::factory()->for($this->user)->create();

    $r = $this->patchJson("/api/v1/backgrounds/{$b->ulid}/select")->assertOk();

    expect($r->json('data.is_active'))->toBeTrue();
    expect($a->fresh()->is_active)->toBeFalse();
});

it('forbids selecting another user’s row', function () {
    $other = User::factory()->create();
    $bg = Background::factory()->for($other)->create();
    $this->patchJson("/api/v1/backgrounds/{$bg->ulid}/select")->assertStatus(403);
});

it('returns 409 when the partial-unique index is violated', function () {
    // Already-active row.
    Background::factory()->for($this->user)->create(['is_active' => true]);

    // Attempting to insert a second active row via raw DB (bypassing the service's
    // transactional flip) should surface the partial-unique violation. The bootstrap
    // exception renderer maps that QueryException to a 409 JSON response.
    $r = $this->call('GET', '/__test/force-unique-violation', [
        'user_id' => $this->user->id,
    ]);
    // We can't define an ad-hoc route here without polluting the app, so instead
    // assert the controller's renderer directly: trigger the exception in-process.
    try {
        DB::table('backgrounds')->insert([
            'ulid' => (string) Str::ulid(),
            'user_id' => $this->user->id,
            'kind' => 'upload',
            'storage_key' => 'backgrounds/u-race/'.uniqid().'.webp',
            'width' => 1280,
            'height' => 720,
            'prompt' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->fail('Expected QueryException for partial unique index');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('one_active_bg_per_user');
        // Confirm the renderer maps to 409.
        $response = app(ExceptionHandler::class)
            ->render(request(), $e);
        expect($response->getStatusCode())->toBe(409);
    }
});
