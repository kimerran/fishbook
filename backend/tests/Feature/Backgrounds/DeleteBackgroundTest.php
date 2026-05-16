<?php

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, $expiration) {
        return 'https://fake-s3.test/'.$path.'?X-Amz-Signature=stub&X-Amz-Expires=3600';
    });
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('soft-deletes and dispatches the purge job with a 7-day delay', function () {
    Queue::fake();
    $bg = Background::factory()->for($this->user)->create();
    $this->deleteJson("/api/v1/backgrounds/{$bg->ulid}")->assertNoContent();

    expect($bg->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(PurgeBackgroundJob::class, function ($job) use ($bg) {
        if ($job->backgroundId !== $bg->id) {
            return false;
        }
        $delay = $job->delay;
        if ($delay === null) {
            return false;
        }
        if ($delay instanceof DateTimeInterface) {
            $secs = $delay->getTimestamp() - now()->getTimestamp();
        } else {
            $secs = (int) $delay;
        }

        return $secs >= 6 * 86400;
    });
});

it('removes the row from index after delete', function () {
    $bg = Background::factory()->for($this->user)->create();
    $this->deleteJson("/api/v1/backgrounds/{$bg->ulid}")->assertNoContent();
    $r = $this->getJson('/api/v1/backgrounds')->assertOk();
    expect($r->json('data'))->toBe([]);
});

it('forbids deleting another user’s row', function () {
    $other = User::factory()->create();
    $bg = Background::factory()->for($other)->create();
    $this->deleteJson("/api/v1/backgrounds/{$bg->ulid}")->assertStatus(403);
});
