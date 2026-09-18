<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function activeEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge(['status' => 'active'], $overrides));
    }

    /**
     * Synthesize a real, valid JPEG on the faked public disk using GD.
     */
    private function putRealImage(string $path, int $w = 800, int $h = 600): void
    {
        $gd = imagecreatetruecolor($w, $h);
        imagefilledrectangle($gd, 0, 0, $w - 1, $h - 1, imagecolorallocate($gd, 100, 150, 200));
        ob_start();
        imagejpeg($gd);
        $bytes = ob_get_clean();
        Storage::disk('public')->put($path, $bytes);
    }

    /**
     * Create a ready legacy photo (null processed paths) whose original exists on disk.
     */
    private function legacyReadyPhoto(Event $event): Photo
    {
        $photo = Photo::factory()->for($event)->create([
            'status'         => 'ready',
            'optimized_path' => null,
            'thumbnail_path' => null,
            'original_path'  => "events/{$event->uuid}/originals/".Str::uuid().'.jpg',
        ]);
        $this->putRealImage($photo->original_path);

        return $photo;
    }

    // ---- Property 9: Backfill is idempotent, targeted, and non-destructive ----

    // Property 9: a legacy ready photo whose original exists gains both
    // processed variants, and the stored variants are real WebP images.
    // Requirements 16.1
    #[Test]
    public function command_backfills_legacy_ready_photo(): void
    {
        Storage::fake('public');
        $event = $this->activeEvent();
        $photo = $this->legacyReadyPhoto($event);

        $this->artisan('photos:process')->assertSuccessful();

        $photo->refresh();

        $this->assertNotNull($photo->optimized_path);
        $this->assertNotNull($photo->thumbnail_path);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($photo->optimized_path));
        $this->assertTrue($disk->exists($photo->thumbnail_path));

        $optimizedInfo = getimagesizefromstring($disk->get($photo->optimized_path));
        $thumbnailInfo = getimagesizefromstring($disk->get($photo->thumbnail_path));
        $this->assertSame('image/webp', $optimizedInfo['mime']);
        $this->assertSame('image/webp', $thumbnailInfo['mime']);
    }

    // Property 9: repeated runs produce the same state (idempotent), and a
    // photo that already carries both processed paths is left untouched.
    // Requirements 16.2, 16.4
    #[Test]
    public function command_is_idempotent_and_skips_already_processed(): void
    {
        Storage::fake('public');
        $event = $this->activeEvent();

        // Legacy photo processed on the first run.
        $photo = $this->legacyReadyPhoto($event);
        $this->artisan('photos:process')->assertSuccessful();
        $photo->refresh();
        $firstOptimized = $photo->optimized_path;
        $firstThumbnail = $photo->thumbnail_path;

        // A photo that already had both processed paths from the start.
        $preOptimized = "events/{$event->uuid}/optimized/".Str::uuid().'.webp';
        $preThumbnail = "events/{$event->uuid}/thumbnails/".Str::uuid().'.webp';
        $preProcessed = Photo::factory()->for($event)->create([
            'status'         => 'ready',
            'optimized_path' => $preOptimized,
            'thumbnail_path' => $preThumbnail,
            'original_path'  => "events/{$event->uuid}/originals/".Str::uuid().'.jpg',
        ]);
        $this->putRealImage($preProcessed->original_path);
        $this->putRealImage($preOptimized);
        $this->putRealImage($preThumbnail);

        // Second run — no-op for both photos.
        $this->artisan('photos:process')->assertSuccessful();

        $photo->refresh();
        $this->assertSame($firstOptimized, $photo->optimized_path);
        $this->assertSame($firstThumbnail, $photo->thumbnail_path);

        $preProcessed->refresh();
        $this->assertSame($preOptimized, $preProcessed->optimized_path);
        $this->assertSame($preThumbnail, $preProcessed->thumbnail_path);
    }

    // Property 9: a ready photo whose original is missing is skipped, and its
    // record is left intact (not deleted, paths stay null). Requirements 16.3, 16.5
    #[Test]
    public function command_skips_photo_with_missing_original(): void
    {
        Storage::fake('public');
        $event = $this->activeEvent();

        // Ready photo with null processed paths but NO original file on disk.
        $photo = Photo::factory()->for($event)->create([
            'status'         => 'ready',
            'optimized_path' => null,
            'thumbnail_path' => null,
            'original_path'  => "events/{$event->uuid}/originals/".Str::uuid().'.jpg',
        ]);

        $this->artisan('photos:process')->assertSuccessful();

        $photo->refresh();
        $this->assertNull($photo->optimized_path);
        $this->assertNull($photo->thumbnail_path);
        $this->assertTrue(Photo::whereKey($photo->id)->exists());
    }

    // Property 9: the backfill never deletes photo records, whether their
    // originals are present or missing. Requirements 15.3, 16.5
    #[Test]
    public function command_does_not_delete_records(): void
    {
        Storage::fake('public');
        $event = $this->activeEvent();

        // 1 legacy photo with its original present.
        $this->legacyReadyPhoto($event);

        // 1 legacy photo whose original is missing.
        Photo::factory()->for($event)->create([
            'status'         => 'ready',
            'optimized_path' => null,
            'thumbnail_path' => null,
            'original_path'  => "events/{$event->uuid}/originals/".Str::uuid().'.jpg',
        ]);

        $countBefore = Photo::count();

        $this->artisan('photos:process')->assertSuccessful();

        $this->assertSame($countBefore, Photo::count());
    }
}
