<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use App\Services\PhotoProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueuedPhotoProcessingTest extends TestCase
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
        $tmp = tempnam(sys_get_temp_dir(), 'img').".{$format}";
        match ($format) {
            'png'   => imagepng($gd, $tmp),
            'webp'  => imagewebp($gd, $tmp),
            default => imagejpeg($gd, $tmp, 90),
        };
        imagedestroy($gd);

        return new UploadedFile($tmp, "photo.{$format}", null, null, true);
    }

    /**
     * Raw bytes of a real GD image (used to place originals directly on disk).
     */
    private function imageBytes(int $width, int $height, string $format = 'jpeg'): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 30, 140, 90));
        $tmp = tempnam(sys_get_temp_dir(), 'raw').".{$format}";
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

    private function activeUploadableEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status'         => 'active',
            'upload_enabled' => true,
        ], $overrides));
    }

    private function upload(Event $event, array $files)
    {
        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $files]);
    }

    /**
     * Create a pending photo row for an event, optionally placing a real
     * original image on the faked public disk at the server path.
     */
    private function pendingPhoto(Event $event, bool $withOriginal = true): Photo
    {
        $uuid = (string) Str::uuid();
        $path = "events/{$event->uuid}/originals/{$uuid}.jpg";

        if ($withOriginal) {
            Storage::disk('public')->put($path, $this->imageBytes(800, 600, 'jpeg'));
        }

        return Photo::create([
            'event_id'          => $event->id,
            'uuid'              => $uuid,
            'original_filename' => 'source.jpg',
            'original_path'     => $path,
            'mime_type'         => 'image/jpeg',
            'file_size'         => 12345,
            'width'             => 800,
            'height'            => 600,
            'status'            => Photo::STATUS_PENDING,
        ]);
    }

    /**
     * A PhotoProcessor that always throws — used to prove process() is not
     * invoked on skip paths, or to simulate processing failure.
     */
    private function throwingProcessor(): PhotoProcessor
    {
        return new class extends PhotoProcessor
        {
            public function process(string $originalPath, string $eventUuid, string $photoUuid): array
            {
                throw new \RuntimeException('boom');
            }
        };
    }

    // Feature: queue-background-processing, Property 1,2: async upload leaves
    // every photo pending with no variants and dispatches one job per photo.
    #[Test]
    public function upload_dispatches_one_job_per_photo_and_leaves_pending(): void
    {
        Storage::fake('public');
        Queue::fake();
        $event = $this->activeUploadableEvent();

        $this->upload($event, [
            $this->makeImage(800, 600, 'jpeg'),
            $this->makeImage(640, 480, 'png'),
            $this->makeImage(500, 500, 'webp'),
        ])->assertRedirect("/e/{$event->slug}");

        $this->assertSame(3, Photo::count());

        $disk = Storage::disk('public');
        foreach (Photo::all() as $photo) {
            $this->assertSame(Photo::STATUS_PENDING, $photo->status);
            $this->assertNull($photo->optimized_path);
            $this->assertNull($photo->thumbnail_path);
            $disk->assertExists($photo->original_path);
        }

        Queue::assertPushed(ProcessPhoto::class, 3);
    }

    // Feature: queue-background-processing, Property 1: no synchronous
    // processing happens during the upload request.
    #[Test]
    public function upload_does_not_process_synchronously(): void
    {
        Storage::fake('public');
        Queue::fake();
        $event = $this->activeUploadableEvent();

        $this->upload($event, [$this->makeImage(800, 600, 'jpeg')])
            ->assertRedirect("/e/{$event->slug}");

        $photo = Photo::firstOrFail();
        $this->assertSame(Photo::STATUS_PENDING, $photo->status);
        $this->assertNull($photo->optimized_path);

        $disk = Storage::disk('public');
        $this->assertEmpty($disk->allFiles("events/{$event->uuid}/optimized"));
        $this->assertEmpty($disk->allFiles("events/{$event->uuid}/thumbnails"));
    }

    // Feature: queue-background-processing, Property 3: a job processes a
    // pending photo into a ready photo with both WebP variants on disk.
    #[Test]
    public function job_processes_pending_photo_to_ready(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();
        $photo = $this->pendingPhoto($event);

        ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame(Photo::STATUS_READY, $photo->status);
        $this->assertSame("events/{$event->uuid}/optimized/{$photo->uuid}.webp", $photo->optimized_path);
        $this->assertSame("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp", $photo->thumbnail_path);

        $disk = Storage::disk('public');
        $disk->assertExists($photo->optimized_path);
        $disk->assertExists($photo->thumbnail_path);

        $optimized = getimagesizefromstring($disk->get($photo->optimized_path));
        $thumbnail = getimagesizefromstring($disk->get($photo->thumbnail_path));
        $this->assertSame('image/webp', $optimized['mime']);
        $this->assertSame('image/webp', $thumbnail['mime']);
    }

    // Feature: queue-background-processing, Property 4,5: the failed hook marks
    // the photo failed, deletes partial variants, and retains the original.
    #[Test]
    public function job_marks_failed_and_cleans_up_on_processor_error(): void
    {
        $this->instance(PhotoProcessor::class, $this->throwingProcessor());

        Storage::fake('public');
        $event = $this->activeUploadableEvent();
        $photo = $this->pendingPhoto($event);

        // Pre-place partial processed files as if a prior attempt half-ran.
        $disk           = Storage::disk('public');
        $optimizedPath  = "events/{$event->uuid}/optimized/{$photo->uuid}.webp";
        $thumbnailPath  = "events/{$event->uuid}/thumbnails/{$photo->uuid}.webp";
        $disk->put($optimizedPath, $this->imageBytes(100, 100, 'webp'));
        $disk->put($thumbnailPath, $this->imageBytes(50, 50, 'webp'));

        $job = new ProcessPhoto($photo->id);
        $job->failed(new \RuntimeException('boom'));

        $photo->refresh();
        $this->assertSame(Photo::STATUS_FAILED, $photo->status);
        $disk->assertMissing($optimizedPath);
        $disk->assertMissing($thumbnailPath);
        $disk->assertExists($photo->original_path);
    }

    // Feature: queue-background-processing, Requirements 5.1,5.2: the job
    // defines a finite retry count and backoff sequence.
    #[Test]
    public function job_defines_finite_retry_config(): void
    {
        $job = new ProcessPhoto(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    // Feature: queue-background-processing, Property 7: a job for a photo that
    // no longer exists is a quiet no-op that writes no files.
    #[Test]
    public function job_is_noop_for_missing_photo(): void
    {
        Storage::fake('public');

        ProcessPhoto::dispatchSync(999999);

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    // Feature: queue-background-processing, Property 8: an already-ready photo
    // is skipped without invoking the processor.
    #[Test]
    public function job_skips_already_ready_photo(): void
    {
        // A throwing processor would fail the test if process() were called.
        $this->instance(PhotoProcessor::class, $this->throwingProcessor());

        Storage::fake('public');
        $event = $this->activeUploadableEvent();
        $uuid  = (string) Str::uuid();

        $photo = Photo::create([
            'event_id'          => $event->id,
            'uuid'              => $uuid,
            'original_filename' => 'ready.jpg',
            'original_path'     => "events/{$event->uuid}/originals/{$uuid}.jpg",
            'optimized_path'    => "events/{$event->uuid}/optimized/{$uuid}.webp",
            'thumbnail_path'    => "events/{$event->uuid}/thumbnails/{$uuid}.webp",
            'mime_type'         => 'image/jpeg',
            'file_size'         => 12345,
            'width'             => 800,
            'height'            => 600,
            'status'            => Photo::STATUS_READY,
        ]);

        ProcessPhoto::dispatchSync($photo->id);

        $photo->refresh();
        $this->assertSame(Photo::STATUS_READY, $photo->status);
        $this->assertSame("events/{$event->uuid}/optimized/{$uuid}.webp", $photo->optimized_path);
        $this->assertSame("events/{$event->uuid}/thumbnails/{$uuid}.webp", $photo->thumbnail_path);
    }

    // Feature: queue-background-processing, Property 9: a photo whose original
    // is missing is marked failed without throwing.
    #[Test]
    public function job_marks_failed_when_original_missing(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();
        $photo = $this->pendingPhoto($event, withOriginal: false);

        ProcessPhoto::dispatchSync($photo->id);

        $photo->refresh();
        $this->assertSame(Photo::STATUS_FAILED, $photo->status);

        $disk = Storage::disk('public');
        $disk->assertMissing("events/{$event->uuid}/optimized/{$photo->uuid}.webp");
        $disk->assertMissing("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp");
    }

    // Feature: queue-background-processing, Property 6: re-processing is
    // idempotent — same paths, still ready, and no duplicate rows.
    #[Test]
    public function reprocessing_is_idempotent_no_duplicate_rows(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();
        $photo = $this->pendingPhoto($event);

        ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();
        $firstOptimized = $photo->optimized_path;
        $firstThumbnail = $photo->thumbnail_path;

        ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame(Photo::STATUS_READY, $photo->status);
        $this->assertSame(1, Photo::count());
        $this->assertSame($firstOptimized, $photo->optimized_path);
        $this->assertSame($firstThumbnail, $photo->thumbnail_path);
    }

    // Feature: queue-background-processing, Property 11: photos are processed
    // independently — one failure does not block the others.
    #[Test]
    public function multiple_photos_independent_one_failure(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        $first  = $this->pendingPhoto($event, withOriginal: true);
        $middle = $this->pendingPhoto($event, withOriginal: false);
        $third  = $this->pendingPhoto($event, withOriginal: true);

        ProcessPhoto::dispatchSync($first->id);
        ProcessPhoto::dispatchSync($middle->id);
        ProcessPhoto::dispatchSync($third->id);

        $this->assertSame(Photo::STATUS_READY, $first->fresh()->status);
        $this->assertSame(Photo::STATUS_FAILED, $middle->fresh()->status);
        $this->assertSame(Photo::STATUS_READY, $third->fresh()->status);
    }

    // Feature: queue-background-processing, Property 10: the gallery shows only
    // ready photos and excludes pending, processing, and failed.
    #[Test]
    public function gallery_shows_only_ready(): void
    {
        Storage::fake('public');
        $event = $this->activeUploadableEvent();

        Photo::factory()->for($event)->create(['status' => Photo::STATUS_READY]);
        Photo::factory()->for($event)->create(['status' => Photo::STATUS_PENDING]);
        Photo::factory()->for($event)->create(['status' => Photo::STATUS_PROCESSING]);
        Photo::factory()->for($event)->create(['status' => Photo::STATUS_FAILED]);

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Gallery')
                ->has('photos', 1)
            );
    }
}
