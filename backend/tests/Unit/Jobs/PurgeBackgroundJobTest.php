<?php

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('deletes the S3 object for a soft-deleted background', function () {
    Storage::fake('s3');
    $u = User::factory()->create();
    $bg = Background::factory()->for($u)->create(['storage_key' => 'backgrounds/u1/x.webp']);
    Storage::disk('s3')->put($bg->storage_key, 'fake');
    $bg->delete();
    (new PurgeBackgroundJob($bg->id))->handle();
    Storage::disk('s3')->assertMissing('backgrounds/u1/x.webp');
});

it('aborts if deleted_at was cleared (restore race)', function () {
    Storage::fake('s3');
    $bg = Background::factory()->create(['storage_key' => 'backgrounds/u2/y.webp']);
    Storage::disk('s3')->put($bg->storage_key, 'fake');
    (new PurgeBackgroundJob($bg->id))->handle();
    Storage::disk('s3')->assertExists('backgrounds/u2/y.webp');
});
