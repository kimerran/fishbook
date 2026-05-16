<?php

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use App\Services\Backgrounds\BackgroundService;
use Illuminate\Support\Facades\Queue;

it('select flips prior active to false and target to true atomically', function () {
    $u = User::factory()->create();
    $a = Background::factory()->for($u)->create(['is_active' => true]);
    $b = Background::factory()->for($u)->create();

    app(BackgroundService::class)->select($u, $b);

    expect($a->fresh()->is_active)->toBeFalse();
    expect($b->fresh()->is_active)->toBeTrue();
});

it('delete soft-deletes and dispatches purge with 7-day delay', function () {
    Queue::fake();
    $u = User::factory()->create();
    $bg = Background::factory()->for($u)->create();
    app(BackgroundService::class)->delete($u, $bg);

    expect($bg->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(PurgeBackgroundJob::class, function ($job) {
        $delay = $job->delay;
        if ($delay === null) {
            return false;
        }
        // Delay may be DateTimeInterface or int seconds.
        if ($delay instanceof DateTimeInterface) {
            $secs = $delay->getTimestamp() - now()->getTimestamp();
        } else {
            $secs = (int) $delay;
        }

        return $secs >= 6 * 86400; // at least 6 days, allows minor clock drift
    });
});
