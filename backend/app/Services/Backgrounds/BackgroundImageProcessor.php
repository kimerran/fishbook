<?php

namespace App\Services\Backgrounds;

use App\Exceptions\Backgrounds\DimensionsTooSmallException;
use App\Exceptions\Backgrounds\FileTooLargeException;
use App\Exceptions\Backgrounds\InvalidImageException;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class BackgroundImageProcessor
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MIN_WIDTH = 1280;

    private const MIN_HEIGHT = 720;

    private const MAX_LONG_EDGE = 2560;

    private const QUALITY = 85;

    /** @var array<int, string> */
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ImageManager $manager,
        private readonly Filesystems $filesystems,
    ) {}

    /** @return array{storage_key:string,width:int,height:int} */
    public function process(UploadedFile $file, int $userId): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new FileTooLargeException('Image exceeds 5 MB.');
        }
        try {
            $img = $this->manager->read($file->getRealPath());
        } catch (\Throwable) {
            throw new InvalidImageException('File is not a decodable image.');
        }
        $mime = $img->origin()->mediaType();
        if (! in_array($mime, self::ALLOWED, true)) {
            throw new InvalidImageException("Unsupported MIME: {$mime}.");
        }
        $w = $img->width();
        $h = $img->height();
        if ($w < self::MIN_WIDTH || $h < self::MIN_HEIGHT) {
            throw new DimensionsTooSmallException("Need at least 1280x720; got {$w}x{$h}.");
        }
        $long = max($w, $h);
        if ($long > self::MAX_LONG_EDGE) {
            $img = $img->scaleDown(self::MAX_LONG_EDGE, self::MAX_LONG_EDGE);
            $w = $img->width();
            $h = $img->height();
        }
        $key = "backgrounds/u{$userId}/".Str::ulid()->toBase32().'.webp';
        $bytes = (string) $img->toWebp(self::QUALITY);
        $this->filesystems->disk('s3')->put($key, $bytes);

        return ['storage_key' => $key, 'width' => $w, 'height' => $h];
    }
}
