<?php

use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('s3');
    $this->user = User::factory()->create();
    $this->background = Background::factory()->for($this->user)->create([
        'storage_key' => 'backgrounds/u'.$this->user->id.'/test.webp',
    ]);
    Storage::disk('s3')->put($this->background->storage_key, 'fake-webp-bytes');
});

it('streams the bytes for a valid signed URL', function () {
    $url = URL::temporarySignedRoute(
        'backgrounds.image',
        now()->addHour(),
        ['background' => $this->background->ulid],
    );
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $r = $this->get($path);

    $r->assertOk();
    $r->assertHeader('Content-Type', 'image/webp');
    // Symfony normalizes header value ordering; assert directive presence instead.
    $cc = $r->headers->get('Cache-Control');
    expect($cc)->toContain('private')->toContain('max-age=3600');
});

it('rejects a request without a valid signature', function () {
    $this->get('/api/v1/backgrounds/'.$this->background->ulid.'/image')
        ->assertStatus(403);
});

it('returns 404 when the storage object is missing', function () {
    Storage::disk('s3')->delete($this->background->storage_key);
    $url = URL::temporarySignedRoute(
        'backgrounds.image',
        now()->addHour(),
        ['background' => $this->background->ulid],
    );
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($path)->assertStatus(404);
});
