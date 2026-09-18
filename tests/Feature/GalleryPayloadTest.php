<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: image-processing
 *
 * Property 6: For every photo, the thumbnail and optimized URL accessors
 * resolve through the public disk to the processed variant when present, and
 * fall back to the original URL when the processed path is null.
 *
 * Property 7: The gallery payload contains exactly the ready photos of the
 * event, and each entry exposes thumbnailUrl and optimizedUrl (never url or
 * mime_type).
 */
class GalleryPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function activeEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status' => 'active',
        ], $overrides));
    }

    private function readyPhoto(Event $event, array $overrides = []): Photo
    {
        return Photo::factory()->for($event)->create(array_merge(['status' => 'ready'], $overrides));
    }

    // ---- Property 7: gallery payload ready-only with both URLs ----

    // Property 7: ready photos with processed paths appear in the payload, each
    // exposing thumbnailUrl and optimizedUrl and never url or mime_type.
    // Requirements 11.1, 11.6, 14.4, 20.1
    #[Test]
    public function gallery_payload_includes_thumbnail_and_optimized_urls_for_ready_photos(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'both-urls']);

        for ($i = 0; $i < 2; $i++) {
            $photo = $this->readyPhoto($event);
            $photo->update([
                'optimized_path' => "events/{$event->uuid}/optimized/{$photo->uuid}.webp",
                'thumbnail_path' => "events/{$event->uuid}/thumbnails/{$photo->uuid}.webp",
            ]);
        }

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Gallery')
                ->has('photos', 2)
                ->has('photos.0.thumbnailUrl')
                ->has('photos.0.optimizedUrl')
                ->missing('photos.0.url')
                ->missing('photos.0.mime_type')
            );
    }

    // Property 7: processing and failed photos never enter the payload; only
    // the ready photo is present. Requirements 11.6, 14.4
    #[Test]
    public function gallery_excludes_processing_and_failed_photos(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'ready-only']);

        $this->readyPhoto($event);
        $this->readyPhoto($event, ['status' => 'processing']);
        $this->readyPhoto($event, ['status' => 'failed']);

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Gallery')
                ->has('photos', 1)
            );
    }

    // ---- Property 6: legacy fallback to the original URL ----

    // Property 6: a photo with null processed paths resolves both URLs to the
    // original public-disk URL. Requirements 9.3, 10.5, 10.6, 11.5, 20.4
    #[Test]
    public function legacy_photo_urls_fall_back_to_original(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'legacy-fallback']);

        $photo = $this->readyPhoto($event, [
            'original_path'  => "events/{$event->uuid}/originals/",
            'optimized_path' => null,
            'thumbnail_path' => null,
        ]);
        // original_path needs the photo uuid, which is only known after create.
        $photo->update([
            'original_path' => "events/{$event->uuid}/originals/{$photo->uuid}.jpg",
        ]);

        $expected = Storage::disk('public')->url($photo->original_path);

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($expected) {
                $page->component('Public/Gallery')->has('photos', 1);

                $item = $page->toArray()['props']['photos'][0];

                $this->assertSame($expected, $item['thumbnailUrl']);
                $this->assertSame($expected, $item['optimizedUrl']);
                $this->assertSame($item['thumbnailUrl'], $item['optimizedUrl']);
            });
    }

    // ---- Property 6: processed paths used when present ----

    // Property 6: a photo with processed paths resolves each URL to its own
    // processed variant, distinct from each other and from the original.
    // Requirements 10.3, 10.4
    #[Test]
    public function processed_photo_urls_use_processed_paths(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'processed-paths']);

        $photo = $this->readyPhoto($event);
        $optimizedPath = "events/{$event->uuid}/optimized/{$photo->uuid}.webp";
        $thumbnailPath = "events/{$event->uuid}/thumbnails/{$photo->uuid}.webp";
        $photo->update([
            'optimized_path' => $optimizedPath,
            'thumbnail_path' => $thumbnailPath,
        ]);

        $expectedOptimized = Storage::disk('public')->url($optimizedPath);
        $expectedThumbnail = Storage::disk('public')->url($thumbnailPath);
        $expectedOriginal = Storage::disk('public')->url($photo->original_path);

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (
                $expectedOptimized,
                $expectedThumbnail,
                $expectedOriginal,
                $photo
            ) {
                $page->component('Public/Gallery')->has('photos', 1);

                $item = $page->toArray()['props']['photos'][0];

                $this->assertSame($expectedThumbnail, $item['thumbnailUrl']);
                $this->assertSame($expectedOptimized, $item['optimizedUrl']);

                $this->assertStringEndsWith("thumbnails/{$photo->uuid}.webp", $item['thumbnailUrl']);
                $this->assertStringEndsWith("optimized/{$photo->uuid}.webp", $item['optimizedUrl']);

                // Variants differ from each other and from the original.
                $this->assertNotSame($item['thumbnailUrl'], $item['optimizedUrl']);
                $this->assertNotSame($expectedOriginal, $item['thumbnailUrl']);
                $this->assertNotSame($expectedOriginal, $item['optimizedUrl']);
            });
    }
}
