<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class PhotoProcessor
{
    private const OPTIMIZED_MAX = 2048;
    private const THUMBNAIL_MAX = 500;
    private const WEBP_QUALITY  = 82;

    /**
     * Generate and store the optimized and thumbnail WebP variants for a
     * stored original. Returns the two public-disk relative paths.
     *
     * @return array{optimized_path: string, thumbnail_path: string}
     *
     * @throws \Throwable on decode, encode, or storage failure
     */
    public function process(string $originalPath, string $eventUuid, string $photoUuid): array
    {
        $disk    = Storage::disk('public');
        $manager = new ImageManager(new Driver());

        $optimizedPath = "events/{$eventUuid}/optimized/{$photoUuid}.webp";
        $thumbnailPath = "events/{$eventUuid}/thumbnails/{$photoUuid}.webp";

        // Read the source bytes once; decode fresh per variant to avoid
        // scaleDown mutation coupling. read() auto-applies EXIF orientation.
        $contents = $disk->get($originalPath);

        // Optimized (max dimension 2048, scale-down only, never upscales).
        $optimized = $manager->read($contents)
            ->scaleDown(width: self::OPTIMIZED_MAX, height: self::OPTIMIZED_MAX);
        $disk->put($optimizedPath, (string) $optimized->toWebp(quality: self::WEBP_QUALITY));
        unset($optimized);

        // Thumbnail (max dimension 500, scale-down only, never upscales).
        $thumbnail = $manager->read($contents)
            ->scaleDown(width: self::THUMBNAIL_MAX, height: self::THUMBNAIL_MAX);
        $disk->put($thumbnailPath, (string) $thumbnail->toWebp(quality: self::WEBP_QUALITY));
        unset($thumbnail);

        return [
            'optimized_path' => $optimizedPath,
            'thumbnail_path' => $thumbnailPath,
        ];
    }
}
