<?php

use App\Models\Background;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

it('uploads and sets the first as active', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg'), 'a.jpg', null, null, true);
    $r = $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertCreated();
    expect($r->json('data.is_active'))->toBeTrue();
    expect(Background::count())->toBe(1);
});

it('rejects under-size images', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/too-small-800x600.jpg'), 'a.jpg', null, null, true);
    $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');
});

it('rejects text spoofed as jpeg via deep MIME sniff', function () {
    // The mimes: rule (finfo) will catch the text fixture first, but either layer is OK.
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/not-an-image.jpg'), 'a.jpg', null, null, true);
    $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertStatus(422);
});

it('rejects files larger than 5 MB', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/too-big-7mb.jpg'), 'a.jpg', null, null, true);
    $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertStatus(422);
});

it('does not auto-flip when an active background exists', function () {
    Background::factory()->for($this->user)->create(['is_active' => true]);
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg'), 'a.jpg', null, null, true);
    $r = $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertCreated();
    expect($r->json('data.is_active'))->toBeFalse();
});

it('ignores mass-assignment of is_active / kind / user_id', function () {
    $other = User::factory()->create();
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg'), 'a.jpg', null, null, true);
    $r = $this->postJson('/api/v1/backgrounds/upload', [
        'image' => $file,
        'is_active' => false,
        'kind' => 'preset',
        'user_id' => $other->id,
    ])->assertCreated();
    $row = Background::first();
    expect($row->user_id)->toBe($this->user->id);
    expect($row->kind)->toBe('upload');
});
