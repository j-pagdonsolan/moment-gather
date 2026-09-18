<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Focused documentation of the Photo status lifecycle
 * (pending -> processing -> ready | failed) and its public gallery visibility.
 *
 * The full queue matrix lives in QueuedPhotoProcessingTest and the broad
 * gallery rules live in PhotoGalleryTest; those are referenced, not duplicated.
 */
class PhotoStatusLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Raw bytes of a real GD image, so PhotoProcessor performs a genuine
     * decode/resize/encode (mirrors the GD synthesis in ImageProcessingTest
     * and QueuedPhotoProcessingTest).
     */
    private function imageBytes(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 60, 120, 180));
        $tmp = tempnam(sys_get_temp_dir(), 'life').'.jpg';
        imagejpeg($gd, $tmp, 90);
        imagedestroy($gd);

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // Feature: automated-testing, R11 photo status lifecycle.
    // R11.1: a pending photo with a real original processes to ready with both
    // WebP variant paths set and present on disk.
    #[Test]
    public function pending_photo_processes_to_ready(): void
    {
        Storage::fake('public');

        $event = Event::factory()->active()->create();
        $photo = Photo::factory()->pending()->for($event)->create();

        Storage::disk('public')->put($photo->original_path, $this->imageBytes(800, 600));

        $this->assertSame(Photo::STATUS_PENDING, $photo->status);

        ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame(Photo::STATUS_READY, $photo->status);
        $this->assertSame("events/{$event->uuid}/optimized/{$photo->uuid}.webp", $photo->optimized_path);
        $this->assertSame("events/{$event->uuid}/thumbnails/{$photo->uuid}.webp", $photo->thumbnail_path);

        $disk = Storage::disk('public');
        $disk->assertExists($photo->optimized_path);
        $disk->assertExists($photo->thumbnail_path);
    }

    // Feature: automated-testing, R11 photo status lifecycle.
    // R11.2: a pending photo whose original is absent transitions to failed
    // (the handle() missing-original branch). Cleanup specifics are covered by
    // ImageProcessingTest / QueuedPhotoProcessingTest and are not duplicated.
    #[Test]
    public function pending_photo_without_original_transitions_to_failed(): void
    {
        Storage::fake('public');

        $event = Event::factory()->active()->create();
        $photo = Photo::factory()->pending()->for($event)->create();

        // No original placed on disk.
        $this->assertSame(Photo::STATUS_PENDING, $photo->status);

        ProcessPhoto::dispatchSync($photo->id);
        $photo->refresh();

        $this->assertSame(Photo::STATUS_FAILED, $photo->status);
    }

    // Feature: automated-testing, R11 photo status lifecycle.
    // R11.3: the public gallery surfaces only ready photos; a non-ready photo
    // on the same active event is excluded.
    #[Test]
    public function gallery_contains_only_the_ready_photo(): void
    {
        Storage::fake('public');

        $event = Event::factory()->active()->create();
        $ready = Photo::factory()->ready()->for($event)->create();
        $pending = Photo::factory()->pending()->for($event)->create();

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($ready, $pending) {
                $page->component('Public/Gallery')->has('photos', 1);

                $uuids = collect($page->toArray()['props']['photos'])->pluck('uuid')->all();

                $this->assertContains($ready->uuid, $uuids);
                $this->assertNotContains($pending->uuid, $uuids);
            });
    }
}
