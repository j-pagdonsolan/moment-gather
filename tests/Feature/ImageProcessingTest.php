<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Synthesize a real image file with GD and wrap it as a test-mode
     * UploadedFile (bypasses is_uploaded_file). Real bytes, so the
     * PhotoProcessor performs genuine decode/resize/encode.
     */
    private function makeImage(int $width, int $height, string $format = 'jpeg'): UploadedFile
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

        return new UploadedFile($tmp, "photo.{$format}", null, null, true);
    }

    /**
     * @return array{0:int,1:int,2:string} width, height, mime of a stored public-disk image
     */
    private function storedDimensions(string $path): array
    {
        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        return [$size[0], $size[1], $size['mime']];
    }

    private function activeUploadableEvent(): Event
    {
        return Event::factory()->create([
            'status'         => 'active',
            'upload_enabled' => true,
        ]);
    }

    private function upload(Event $event, array $files)
    {
        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $files]);
    }

    // Feature: image-processing, Property 5 (server-generated UUID paths) &
    // Property 8 (status reflects processing outcome): a successful upload
    // stores the original, optimized, and thumbnail and marks the photo ready.
    // Requirements 2.2, 3.1, 4.5, 5.5
    #[Test]
    public function upload_stores_original_optimized_thumbnail_and_marks_ready(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $response = $this->upload($event, [$this->makeImage(1000, 800, 'jpeg')]);

        $response->assertRedirect("/e/{$event->slug}");
        $this->assertSame(1, Photo::count());

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame(Photo::STATUS_READY, $photo->status);
        $this->assertNotNull($photo->optimized_path);
        $this->assertNotNull($photo->thumbnail_path);

        $this->assertSame("events/{$event->uuid}/optimized/{$photo->uuid}.webp", $photo->optimized_path);
        $this->assertSame("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp", $photo->thumbnail_path);

        $disk = Storage::disk('public');
        $disk->assertExists($photo->original_path);
        $disk->assertExists($photo->optimized_path);
        $disk->assertExists($photo->thumbnail_path);
    }

    // Feature: image-processing, Property 1 (optimized within bound) &
    // Property 2 (thumbnail within bound): a large image is scaled down for
    // both variants, encoded as WebP, and the aspect ratio is preserved.
    // Requirements 4.1-4.4, 5.1-5.4
    #[Test]
    public function large_image_is_reduced(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $this->upload($event, [$this->makeImage(3000, 2000, 'jpeg')])
            ->assertRedirect("/e/{$event->slug}");

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        [$ow, $oh, $omime] = $this->storedDimensions($photo->optimized_path);
        $this->assertLessThanOrEqual(2048, max($ow, $oh));
        $this->assertSame('image/webp', $omime);

        [$tw, $th, $tmime] = $this->storedDimensions($photo->thumbnail_path);
        $this->assertLessThanOrEqual(500, max($tw, $th));
        $this->assertSame('image/webp', $tmime);

        // Aspect ratio preserved (3000/2000 = 1.5).
        $this->assertLessThan(0.05, abs(($ow / $oh) - (3000 / 2000)));
    }

    // Feature: image-processing, Property 1 & Property 2: a small image is
    // never upscaled; both variants keep the original dimensions.
    // Requirements 4.2, 5.2
    #[Test]
    public function small_image_is_not_upscaled(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $this->upload($event, [$this->makeImage(300, 200, 'jpeg')])
            ->assertRedirect("/e/{$event->slug}");

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        [$ow, $oh] = $this->storedDimensions($photo->optimized_path);
        $this->assertSame(300, $ow);
        $this->assertSame(200, $oh);

        [$tw, $th] = $this->storedDimensions($photo->thumbnail_path);
        $this->assertSame(300, $tw);
        $this->assertSame(200, $th);
    }

    // Feature: image-processing, Property 4 (orientation preserved): a portrait
    // original produces portrait variants (height greater than width).
    // Requirements 6.1, 6.2
    #[Test]
    public function portrait_image_stays_portrait(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $this->upload($event, [$this->makeImage(1000, 1500, 'jpeg')])
            ->assertRedirect("/e/{$event->slug}");

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        [$ow, $oh] = $this->storedDimensions($photo->optimized_path);
        $this->assertGreaterThan($ow, $oh);

        [$tw, $th] = $this->storedDimensions($photo->thumbnail_path);
        $this->assertGreaterThan($tw, $th);
    }

    // Feature: image-processing: every supported format (JPG/JPEG, PNG, WEBP)
    // processes successfully into stored WebP variants.
    // Requirements 7.1, 8.1
    #[Test]
    public function supported_formats_process(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            Storage::fake('public');
            $event = $this->activeUploadableEvent();

            $this->upload($event, [$this->makeImage(600, 400, $format)])
                ->assertRedirect("/e/{$event->slug}");

            $photo = $event->photos()->first();
            $this->assertNotNull($photo, "Expected {$format} upload to persist a photo.");
            \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
            $photo->refresh();
            $this->assertSame(Photo::STATUS_READY, $photo->status, "Expected {$format} to be ready.");

            $disk = Storage::disk('public');
            $disk->assertExists($photo->optimized_path);
            $disk->assertExists($photo->thumbnail_path);

            [, , $omime] = $this->storedDimensions($photo->optimized_path);
            [, , $tmime] = $this->storedDimensions($photo->thumbnail_path);
            $this->assertSame('image/webp', $omime);
            $this->assertSame('image/webp', $tmime);
        }
    }

    // Feature: image-processing, Property 3 (original preserved): the stored
    // original is byte-for-byte identical to the uploaded bytes after
    // processing. Requirements 3.2, 3.3, 8.2, 15.1
    #[Test]
    public function original_bytes_unchanged_after_processing(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $file = $this->makeImage(800, 600, 'jpeg');
        // Test-mode UploadedFile moves the temp file on store, so hash first.
        $expected = md5_file($file->getRealPath());

        $this->upload($event, [$file])
            ->assertRedirect("/e/{$event->slug}");

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame($expected, md5(Storage::disk('public')->get($photo->original_path)));
    }

    // Feature: image-processing: a non-image upload is rejected by validation;
    // no photo row is created and nothing is stored.
    // Requirements 7.1, 18.1, 18.2, 18.3
    #[Test]
    public function invalid_file_is_rejected(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $file = UploadedFile::fake()->create('bad.txt', 10, 'text/plain');

        $this->upload($event, [$file])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, Photo::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    // Feature: image-processing: more than 20 files in one request is rejected;
    // no photo row is created. Requirement 17.1
    #[Test]
    public function more_than_twenty_files_rejected(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $files = [];
        for ($i = 0; $i < 21; $i++) {
            $files[] = $this->makeImage(50, 50, 'jpeg');
        }

        $this->upload($event, $files)->assertSessionHasErrors('photos');
        $this->assertSame(0, Photo::count());
    }

    // Feature: image-processing, Property 5 (server-generated UUID paths): an
    // adversarial client filename never leaks into storage paths; processed
    // files are named by the photo UUID. Requirements 13.3, 18.4
    #[Test]
    public function adversarial_filename_uses_uuid_paths(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        // Real jpeg bytes but a path-traversal client name.
        $gd = imagecreatetruecolor(400, 300);
        imagefilledrectangle($gd, 0, 0, 399, 299, imagecolorallocate($gd, 10, 20, 30));
        $tmp = tempnam(sys_get_temp_dir(), 'evil') . '.jpg';
        imagejpeg($gd, $tmp, 90);
        imagedestroy($gd);
        $file = new UploadedFile($tmp, '../../evil.jpg', null, null, true);

        $this->upload($event, [$file])->assertRedirect("/e/{$event->slug}");

        $photo = Photo::first();
        \App\Jobs\ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame("events/{$event->uuid}/optimized/{$photo->uuid}.webp", $photo->optimized_path);
        $this->assertSame("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp", $photo->thumbnail_path);
    }

    // Feature: image-processing, Property 8 (status reflects processing
    // outcome): when processing throws, the photo is marked failed, partial
    // processed files are cleaned up, the original is kept, and the guest sees
    // a friendly response with no exception text.
    // Requirements 14.1, 14.2, 14.3, 15.2
    #[Test]
    public function processing_failure_marks_failed_and_cleans_up(): void
    {
        Storage::fake('public');
        // Queue is deferred: the upload only enqueues the job, so the request
        // succeeds regardless of downstream processing outcome.
        \Illuminate\Support\Facades\Queue::fake();
        $event = $this->activeUploadableEvent();

        $response = $this->upload($event, [$this->makeImage(600, 400, 'jpeg')]);

        // Handled gracefully: redirect back, not a 500.
        $response->assertRedirect("/e/{$event->slug}");
        $response->assertSessionHasNoErrors();

        $photo = Photo::first();

        // Simulate the job lifecycle: retries are exhausted and the failed()
        // handler runs. Pre-place partial processed files so the cleanup
        // assertion below is meaningful.
        $disk = Storage::disk('public');
        $disk->put("events/{$event->uuid}/optimized/{$photo->uuid}.webp", 'partial');
        $disk->put("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp", 'partial');

        $job = new \App\Jobs\ProcessPhoto($photo->id);
        $job->failed(new \RuntimeException('boom'));
        $photo->refresh();

        $this->assertSame(Photo::STATUS_FAILED, $photo->status);

        $disk->assertMissing("events/{$event->uuid}/optimized/{$photo->uuid}.webp");
        $disk->assertMissing("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp");
        // Original is retained.
        $disk->assertExists($photo->original_path);

        // No exception detail leaked to the guest.
        $this->assertStringNotContainsString('boom', (string) session('success'));
    }
}
