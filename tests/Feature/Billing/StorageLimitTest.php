<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 3: Storage-limit atomicity.
//
// storageUsedBytes = SUM(photos.file_size) over the owner's non-soft-deleted
// photos across their (non-soft-deleted) events. An upload batch summing to B
// bytes for an owner with usage S and storage limit L is accepted iff S+B <= L;
// otherwise it is rejected atomically (403, zero stored, zero dispatched).
// Validates: Requirements 9.1, 9.2, 9.3, 9.5.
class StorageLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
    }

    /**
     * Build an UploadedFile from a committed fixture in test mode so it
     * bypasses the is_uploaded_file() check.
     */
    private function fixtureFile(string $name = 'sample.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile(
            base_path("tests/Fixtures/{$name}"),
            $name,
            $mime,
            null,
            true // test mode
        );
    }

    private function uploadableEvent(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->create(array_merge([
            'status'         => 'active',
            'upload_enabled' => true,
        ], $overrides));
    }

    private function upload(Event $event, array $photos)
    {
        // Uploads are rate limited per IP; flush the limiter store between
        // HTTP requests so tests aren't throttled.
        cache()->flush();

        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $photos]);
    }

    #[Test]
    public function storage_usage_is_summed_correctly(): void
    {
        $owner = User::factory()->create();

        // Two events for the owner, each with photos of known file_size.
        $eventA = $this->uploadableEvent($owner);
        $eventB = $this->uploadableEvent($owner);

        Photo::factory()->for($eventA)->create(['file_size' => 1000]);
        Photo::factory()->for($eventA)->create(['file_size' => 2500]);
        Photo::factory()->for($eventB)->create(['file_size' => 500]);

        // A soft-deleted photo must be EXCLUDED from the sum.
        $deleted = Photo::factory()->for($eventA)->create(['file_size' => 9_000_000]);
        $deleted->delete();

        // Another user's photo must NOT be counted (owner isolation).
        $other = User::factory()->create();
        $otherEvent = $this->uploadableEvent($other);
        Photo::factory()->for($otherEvent)->create(['file_size' => 7_000_000]);

        $billing = app(BillingService::class);

        // 1000 + 2500 + 500 = 4000 (soft-deleted and other-owner excluded).
        $this->assertSame(4000, $billing->storageUsedBytes($owner));
        $this->assertSame(7_000_000, $billing->storageUsedBytes($other));
    }

    #[Test]
    public function within_storage_limit_accepted(): void
    {
        // Keep the photo-count dimension out of the way; only storage governs.
        config([
            'plans.free.max_photos_per_event' => 100000,
            'plans.free.max_storage_bytes'    => 5000,
        ]);

        $owner = User::factory()->create();
        $event = $this->uploadableEvent($owner);

        // Existing usage 3000 bytes; a small fixture (160 bytes) stays well
        // under the 5000 limit (3000 + 160 <= 5000).
        Photo::factory()->for($event)->create(['file_size' => 3000]);

        $response = $this->upload($event, [$this->fixtureFile()]);

        $response->assertRedirect("/e/{$event->slug}");
        $response->assertSessionHasNoErrors();

        // The new photo was stored (the pre-existing one plus the upload).
        $this->assertSame(2, $event->photos()->count());
        Queue::assertPushed(ProcessPhoto::class, 1);
    }

    #[Test]
    public function over_storage_limit_rejected_atomically(): void
    {
        // Only storage governs; the event photo cap is out of the way.
        config([
            'plans.free.max_photos_per_event' => 100000,
            'plans.free.max_storage_bytes'    => 10000,
        ]);

        $owner = User::factory()->create();
        $event = $this->uploadableEvent($owner);

        // Existing usage already equals the limit; any incoming bytes (>0)
        // push used + incoming over the limit.
        Photo::factory()->for($event)->create(['file_size' => 10000]);

        $response = $this->upload($event, [$this->fixtureFile()]);

        $response->assertForbidden();

        // Nothing stored beyond the pre-existing photo; nothing dispatched.
        $this->assertSame(1, $event->photos()->count());
        $this->assertSame(1, Photo::count());
        Queue::assertNothingPushed();
    }

    // Property 3: bounded-loop exploration of the storage dimension via the
    // BillingService gate. With the photo-count dimension satisfied, the gate
    // returns true iff S + B <= L.
    #[Test]
    public function storage_gate_accepts_iff_used_plus_incoming_within_limit(): void
    {
        $billing = app(BillingService::class);

        for ($i = 0; $i < 40; $i++) {
            // Keep the photo-count dimension non-binding: high per-event cap,
            // and the event carries at most one existing photo.
            config(['plans.free.max_photos_per_event' => 100000]);

            $existing = random_int(0, 20000); // S: existing bytes
            $incoming = random_int(1, 20000); // B: incoming bytes (>0)
            $limit    = random_int(0, 40000); // L: storage limit

            config(['plans.free.max_storage_bytes' => $limit]);

            $owner = User::factory()->create();
            $event = $this->uploadableEvent($owner);
            Photo::factory()->for($event)->create(['file_size' => $existing]);

            $expected = ($existing + $incoming) <= $limit;

            $this->assertSame(
                $existing,
                $billing->storageUsedBytes($owner),
                "storageUsedBytes should equal the single photo's file_size (S={$existing})."
            );

            $this->assertSame(
                $expected,
                $billing->canUploadPhotos($owner, $event, 1, $incoming),
                "Storage gate must be (S+B <= L): S={$existing}, B={$incoming}, L={$limit}."
            );
        }
    }
}
