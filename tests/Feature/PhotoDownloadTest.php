<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: photo-gallery, single-photo download.
 *
 * Route: GET /e/{slug}/photos/{photo}/download (public.events.photos.download).
 * Controller resolves an active event by slug, then the photo by uuid within
 * that event scoped to status=ready, then streams the stored original as a
 * forced download. Any resolution miss, or a missing file on disk, yields 404.
 */
class PhotoDownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an active event with an on-disk ready photo. Returns the photo.
     * Assumes Storage::fake('public') has already been called by the caller.
     */
    private function readyPhotoWithFile(Event $event, array $overrides = [], string $bytes = 'fake-bytes'): Photo
    {
        $photo = Photo::factory()->for($event)->create(array_merge([
            'status' => Photo::STATUS_READY,
        ], $overrides));

        Storage::disk('public')->put($photo->original_path, $bytes);

        return $photo;
    }

    private function downloadUrl(string $slug, string $photo): string
    {
        return "/e/{$slug}/photos/{$photo}/download";
    }

    // Property 7 + 10: a ready photo of an active event whose file exists is
    // streamed as a forced download with the stored original_filename, and the
    // streamed bytes equal the stored bytes exactly (no transformation).
    // Requirements 7.2, 7.3, 11.3
    #[Test]
    public function guest_can_download_a_ready_photo(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'active']);

        $photo = $this->readyPhotoWithFile(
            $event,
            ['original_filename' => 'my-photo.jpg'],
            'fake-bytes'
        );

        $response = $this->get($this->downloadUrl($event->slug, $photo->uuid));

        $response->assertOk();

        // Content-Disposition: attachment; filename=my-photo.jpg
        $response->assertDownload('my-photo.jpg');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', (string) $disposition);
        $this->assertStringContainsString('my-photo.jpg', (string) $disposition);

        // Byte-for-byte fidelity: the streamed body equals the stored bytes.
        $this->assertSame('fake-bytes', $response->streamedContent());
    }

    // Property 8: an unknown slug does not resolve to an active event → 404.
    // Requirement 7.4
    #[Test]
    public function download_from_nonexistent_event_returns_404(): void
    {
        Storage::fake('public');

        $this->get($this->downloadUrl('nope', (string) Str::uuid()))
            ->assertNotFound();
    }

    // Property 8: an archived event is not active → 404. Requirement 7.4
    #[Test]
    public function download_from_archived_event_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'archived']);
        $photo = $this->readyPhotoWithFile($event);

        $this->get($this->downloadUrl($event->slug, $photo->uuid))
            ->assertNotFound();
    }

    // Property 8: a draft event is not active → 404. Requirement 7.4
    #[Test]
    public function download_from_draft_event_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'draft']);
        $photo = $this->readyPhotoWithFile($event);

        $this->get($this->downloadUrl($event->slug, $photo->uuid))
            ->assertNotFound();
    }

    // Property 8: a uuid that matches no photo in the event → 404.
    // Requirement 7.5
    #[Test]
    public function download_unknown_photo_uuid_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'active']);

        $this->get($this->downloadUrl($event->slug, (string) Str::uuid()))
            ->assertNotFound();
    }

    // Property 8 (key cross-event security): a ready photo belonging to event B
    // must not be downloadable through event A's slug. Requirement 7.6
    #[Test]
    public function download_photo_from_another_event_returns_404(): void
    {
        Storage::fake('public');
        $eventA = Event::factory()->create(['status' => 'active']);
        $eventB = Event::factory()->create(['status' => 'active']);

        $photoB = $this->readyPhotoWithFile($eventB);

        $this->get($this->downloadUrl($eventA->slug, $photoB->uuid))
            ->assertNotFound();
    }

    // Property 8: supplying the numeric database id in the {photo} slot instead
    // of the uuid must not resolve → 404. Requirement 7.8
    #[Test]
    public function download_numeric_id_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'active']);
        $photo = $this->readyPhotoWithFile($event);

        $this->get($this->downloadUrl($event->slug, (string) $photo->id))
            ->assertNotFound();
    }

    // Property 8: a photo that exists in the event but is not ready → 404.
    // Requirement 7.7
    #[Test]
    public function download_non_ready_photo_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'active']);

        $photo = $this->readyPhotoWithFile($event, ['status' => Photo::STATUS_PENDING]);

        $this->get($this->downloadUrl($event->slug, $photo->uuid))
            ->assertNotFound();
    }

    // Property 9: a valid ready photo whose file is absent from disk yields a
    // safe 404 with no filesystem path leaked in the body.
    // Requirements 8.2, 8.3
    #[Test]
    public function download_missing_file_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'active']);

        // Ready photo, but deliberately DO NOT put the file on disk.
        $photo = Photo::factory()->for($event)->create(['status' => Photo::STATUS_READY]);

        $response = $this->get($this->downloadUrl($event->slug, $photo->uuid));

        $response->assertNotFound();
        $response->assertDontSee($photo->original_path);
    }
}
