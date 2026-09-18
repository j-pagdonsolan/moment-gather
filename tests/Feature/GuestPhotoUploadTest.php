<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuestPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an UploadedFile from a committed fixture in test mode so it
     * bypasses the is_uploaded_file() check. gd is not required because
     * the fixtures are real image files read via getimagesize().
     */
    private function fixtureFile(string $name, string $mime): UploadedFile
    {
        return new UploadedFile(
            base_path("tests/Fixtures/{$name}"),
            $name,
            $mime,
            null,
            true // test mode
        );
    }

    private function uploadableEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status'         => 'active',
            'upload_enabled' => true,
        ], $overrides));
    }

    private function upload(Event $event, array $photos, array $extra = [])
    {
        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", array_merge(['photos' => $photos], $extra));
    }

    // Feature: guest-photo-upload, Property 1: an unauthenticated guest can
    // upload supported photos to an uploadable event and they are persisted.
    // Requirement 19.1
    #[Test]
    public function guest_can_upload_without_authentication(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        $response = $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')]);

        // Not redirected to login; sent back to the referring event page.
        $response->assertRedirect("/e/{$event->slug}");
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Photo::count());
    }

    // Feature: guest-photo-upload, Property 5: every supported format
    // (JPG, JPEG, PNG, WEBP) is accepted. Requirement 19.2
    #[Test]
    public function jpg_jpeg_png_webp_are_accepted(): void
    {
        $cases = [
            ['sample.jpg', 'image/jpeg'],
            ['sample.jpeg', 'image/jpeg'],
            ['sample.png', 'image/png'],
            ['sample.webp', 'image/webp'],
        ];

        foreach ($cases as [$name, $mime]) {
            Storage::fake('public');
            Queue::fake();
            $event = $this->uploadableEvent();

            $this->upload($event, [$this->fixtureFile($name, $mime)])
                ->assertRedirect("/e/{$event->slug}")
                ->assertSessionHasNoErrors();

            $this->assertSame(
                1,
                $event->photos()->count(),
                "Expected {$name} ({$mime}) to be accepted."
            );
        }
    }

    // Feature: guest-photo-upload, Property 5: an unsupported file type is
    // rejected and no photo is stored. Requirement 19.2
    #[Test]
    public function unsupported_file_type_is_rejected(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        $file = new UploadedFile(
            base_path('tests/Fixtures/not-an-image.jpg'),
            'file.txt',
            'text/plain',
            null,
            true
        );

        $this->upload($event, [$file])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 5: a file larger than the maximum
    // allowed size is rejected. Requirement 19.2
    #[Test]
    public function file_over_max_size_is_rejected(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        // 21000 KB > 20480 KB limit. Rejected by size (or image) rule; either
        // way the observable behaviour required is rejection with no photo.
        $file = UploadedFile::fake()->create('big.jpg', 21000, 'image/jpeg');

        $this->upload($event, [$file])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 5: more than the maximum number of
    // files in a single request is rejected. Requirement 19.2
    #[Test]
    public function more_than_max_files_is_rejected(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        $files = [];
        for ($i = 0; $i < 21; $i++) {
            $files[] = $this->fixtureFile('sample.jpg', 'image/jpeg');
        }

        $this->upload($event, $files)->assertSessionHasErrors('photos');
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 5: a file with a valid extension
    // but whose bytes are not a decodable image is rejected. Requirement 19.2
    #[Test]
    public function invalid_image_is_rejected(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        // not-an-image.jpg is non-image bytes with a .jpg name & image mime.
        $this->upload($event, [$this->fixtureFile('not-an-image.jpg', 'image/jpeg')])
            ->assertSessionHasErrors('photos.0');
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Properties 6, 7, 8, 11, 13: a successful
    // upload persists server-authoritative metadata (event association, storage
    // path/filename, status, original filename, UUID). Requirement 19.5
    #[Test]
    public function upload_to_active_enabled_event_succeeds_and_persists_metadata(): void
    {
        Storage::fake('public');
        Queue::fake();
        $event = $this->uploadableEvent();

        $file = new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'my vacation.jpg',
            'image/jpeg',
            null,
            true
        );

        $response = $this->upload($event, [$file]);
        $response->assertRedirect("/e/{$event->slug}");
        $response->assertSessionHas('success');

        $this->assertSame(1, Photo::count());
        $photo = Photo::firstOrFail();

        // Property 6: event association is the slug-resolved event.
        $this->assertSame($event->id, $photo->event_id);

        // Property 8: status is pending until the queued job processes it.
        $this->assertSame(Photo::STATUS_PENDING, $photo->status);

        // Property 11: client's original filename preserved.
        $this->assertSame('my vacation.jpg', $photo->original_filename);

        // Property 7: server-built storage path and filename.
        $expectedPath = "events/{$event->uuid}/originals/{$photo->uuid}.jpg";
        $this->assertSame($expectedPath, $photo->original_path);
        $this->assertSame("{$photo->uuid}.jpg", basename($photo->original_path));
        $this->assertNotSame('my vacation.jpg', basename($photo->original_path));

        // Property 13: UUID generated and non-empty.
        $this->assertNotEmpty($photo->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $photo->uuid
        );

        // Dimensions from getimagesize of the 1x1 fixture.
        $this->assertSame(1, $photo->width);
        $this->assertSame(1, $photo->height);

        Storage::disk('public')->assertExists($photo->original_path);

        // A ProcessPhoto job is dispatched to handle async processing.
        Queue::assertPushed(ProcessPhoto::class);
    }

    // Feature: guest-photo-upload, Property 3: uploading to a draft event
    // resolves to 404. Requirement 19.3
    #[Test]
    public function upload_to_draft_event_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'draft', 'upload_enabled' => true]);

        $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')])
            ->assertNotFound();
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 3: uploading to an archived event
    // resolves to 404. Requirement 19.3
    #[Test]
    public function upload_to_archived_event_returns_404(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create(['status' => 'archived', 'upload_enabled' => true]);

        $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')])
            ->assertNotFound();
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 3: uploading to a soft-deleted
    // event resolves to 404. Requirement 19.3
    #[Test]
    public function upload_to_soft_deleted_event_returns_404(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();
        $event->delete();

        $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')])
            ->assertNotFound();
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Property 4: uploading to an active event
    // whose uploads are disabled is forbidden (403). Requirement 19.4
    #[Test]
    public function upload_when_upload_disabled_returns_403(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent(['upload_enabled' => false]);

        $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')])
            ->assertForbidden();
        $this->assertSame(0, Photo::count());
    }

    // Feature: guest-photo-upload, Properties 6 & 8: client-supplied event_id,
    // status, and original_path are ignored in favour of server values.
    // Requirement 19.6
    #[Test]
    public function client_cannot_override_event_id_status_or_path(): void
    {
        Storage::fake('public');
        Queue::fake();
        $target = $this->uploadableEvent();
        $other  = $this->uploadableEvent();

        $this->upload(
            $target,
            [$this->fixtureFile('sample.jpg', 'image/jpeg')],
            [
                'event_id'      => $other->id,
                'status'        => 'failed',
                'original_path' => '../../evil.jpg',
            ]
        )->assertRedirect("/e/{$target->slug}");

        $photo = Photo::firstOrFail();
        $this->assertSame($target->id, $photo->event_id);
        $this->assertSame(Photo::STATUS_PENDING, $photo->status);
        $this->assertStringStartsWith(
            "events/{$target->uuid}/originals/",
            $photo->original_path
        );
    }

    // Feature: guest-photo-upload, Property 14: requests beyond the per-minute
    // rate limit are throttled with HTTP 429. Requirement 19.7
    #[Test]
    public function excessive_requests_are_throttled(): void
    {
        Storage::fake('public');
        $event = $this->uploadableEvent();

        // 10 requests are allowed per minute per IP.
        for ($i = 0; $i < 10; $i++) {
            $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')]);
        }

        $this->upload($event, [$this->fixtureFile('sample.jpg', 'image/jpeg')])
            ->assertStatus(429);
    }
}
