<?php

use App\Exceptions\Backgrounds\DimensionsTooSmallException;
use App\Exceptions\Backgrounds\FileTooLargeException;
use App\Exceptions\Backgrounds\InvalidImageException;
use App\Services\Backgrounds\BackgroundImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('s3'));

function uploadFixture(string $name): UploadedFile
{
    return new UploadedFile(base_path("tests/fixtures/backgrounds/$name"), $name, null, null, true);
}

it('accepts a 1280x720 jpeg and stores a webp', function () {
    $result = app(BackgroundImageProcessor::class)->process(uploadFixture('valid-1280x720.jpg'), 7);
    expect($result['width'])->toBe(1280);
    expect($result['height'])->toBe(720);
    expect($result['storage_key'])->toStartWith('backgrounds/u7/')->toEndWith('.webp');
    Storage::disk('s3')->assertExists($result['storage_key']);
});

it('rejects an 800x600 image', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(uploadFixture('too-small-800x600.jpg'), 7))
        ->toThrow(DimensionsTooSmallException::class);
});

it('rejects a 7MB file', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(uploadFixture('too-big-7mb.jpg'), 7))
        ->toThrow(FileTooLargeException::class);
});

it('rejects a text file masquerading as a jpeg', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(uploadFixture('not-an-image.jpg'), 7))
        ->toThrow(InvalidImageException::class);
});

it('strips EXIF GPS data on re-encode', function () {
    $result = app(BackgroundImageProcessor::class)->process(uploadFixture('with-gps-exif.jpg'), 7);
    $bytes = Storage::disk('s3')->get($result['storage_key']);
    // WebP doesn't carry JPEG EXIF chunks; the marker should not appear.
    expect(str_contains($bytes, "Exif\x00\x00"))->toBeFalse();
});
