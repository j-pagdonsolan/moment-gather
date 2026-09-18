<?php

namespace Tests\Unit;

use App\Services\PhotoProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: automated-testing, R23 PhotoProcessor service.
//
// Drives App\Services\PhotoProcessor::process(...) directly against a faked
// public disk. ImageProcessingTest exercises the same guarantees at the HTTP
// level; this file verifies the service in isolation (no DB, no models).
class PhotoProcessorTest extends TestCase
{
    /**
     * Synthesize a real image with GD and return its raw bytes. Mirrors
     * ImageProcessingTest::makeImage but returns bytes for the fake disk
     * instead of an UploadedFile.
     */
    private function makeImageBytes(int $width, int $height, string $format = 'jpeg'): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 120, 90, 200));
        $tmp = tempnam(sys_get_temp_dir(), 'img') . ".{$format}";
        match ($format) {
            'png'   => imagepng($gd, $tmp),
            'webp'  => imagewebp($gd, $tmp),
            default => imagejpeg($gd, $tmp, 90),
        };
        imagedestroy($gd);

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * @return array{0:int,1:int,2:string} width, height, mime of a stored public-disk image
     */
    private function storedDimensions(string $path): array
    {
        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        return [$size[0], $size[1], $size['mime']];
    }

    /**
     * Write synthesized bytes to the originals path and process them.
     *
     * @return array{0:array{optimized_path:string,thumbnail_path:string},1:string,2:string}
     *         result, eventUuid, photoUuid
     */
    private function processBytes(string $bytes): array
    {
        $eventUuid = (string) Str::uuid();
        $photoUuid = (string) Str::uuid();
        $originalPath = "events/{$eventUuid}/originals/{$photoUuid}.jpg";

        Storage::disk('public')->put($originalPath, $bytes);

        $result = app(PhotoProcessor::class)->process($originalPath, $eventUuid, $photoUuid);

        return [$result, $eventUuid, $photoUuid];
    }

    // Feature: automated-testing, R23 PhotoProcessor service.
    // R23.2: a large original (3000x2000) is written as decodable WebP variants
    // at the expected UUID paths, optimized max dim <= 2048, thumbnail <= 500.
    #[Test]
    public function large_image_is_reduced_and_stored_as_webp(): void
    {
        Storage::fake('public');

        [$result, $eventUuid, $photoUuid] = $this->processBytes(
            $this->makeImageBytes(3000, 2000, 'jpeg')
        );

        // Returned paths match the PhotoProcessor naming contract exactly.
        $this->assertSame("events/{$eventUuid}/optimized/{$photoUuid}.webp", $result['optimized_path']);
        $this->assertSame("events/{$eventUuid}/thumbnails/{$photoUuid}.webp", $result['thumbnail_path']);

        $disk = Storage::disk('public');
        $disk->assertExists($result['optimized_path']);
        $disk->assertExists($result['thumbnail_path']);

        [$ow, $oh, $omime] = $this->storedDimensions($result['optimized_path']);
        $this->assertSame('image/webp', $omime);
        $this->assertLessThanOrEqual(2048, max($ow, $oh));

        [$tw, $th, $tmime] = $this->storedDimensions($result['thumbnail_path']);
        $this->assertSame('image/webp', $tmime);
        $this->assertLessThanOrEqual(500, max($tw, $th));
    }

    // Feature: automated-testing, R23 PhotoProcessor service.
    // R23.3: a small original (300x200) is never upscaled — neither variant
    // exceeds the original dimensions.
    #[Test]
    public function small_image_is_not_upscaled(): void
    {
        Storage::fake('public');

        [$result] = $this->processBytes($this->makeImageBytes(300, 200, 'jpeg'));

        [$ow, $oh, $omime] = $this->storedDimensions($result['optimized_path']);
        $this->assertSame('image/webp', $omime);
        $this->assertSame(300, $ow);
        $this->assertSame(200, $oh);

        [$tw, $th, $tmime] = $this->storedDimensions($result['thumbnail_path']);
        $this->assertSame('image/webp', $tmime);
        $this->assertLessThanOrEqual(300, $tw);
        $this->assertLessThanOrEqual(200, $th);
    }

    // Feature: automated-testing, R23 PhotoProcessor service.
    // R23.4: a non-image original causes process() to throw.
    #[Test]
    public function non_image_input_throws(): void
    {
        Storage::fake('public');

        $eventUuid = (string) Str::uuid();
        $photoUuid = (string) Str::uuid();
        $originalPath = "events/{$eventUuid}/originals/{$photoUuid}.jpg";

        Storage::disk('public')->put($originalPath, 'this is not an image');

        $this->expectException(\Throwable::class);

        app(PhotoProcessor::class)->process($originalPath, $eventUuid, $photoUuid);
    }
}
