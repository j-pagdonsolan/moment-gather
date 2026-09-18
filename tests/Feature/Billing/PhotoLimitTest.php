<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 2: Photo-limit atomicity
//
// Ground truth (design Property 2): for an upload batch of N files to an event
// whose owner has C non-deleted photos and per-event plan limit M, the batch is
// accepted (all N stored AND N ProcessPhoto jobs dispatched) iff C + N <= M;
// otherwise the response is 403 with ZERO files stored and ZERO jobs dispatched.
//
// PublicPhotoUploadController@store enforces this against the event OWNER's plan
// (Free = 100, Pro = 5000 photos/event) BEFORE storing or dispatching anything,
// coexisting with the abuse cap (config uploads.max_per_event = 500). The plan
// per-event limit is overridden here (config plans.free.max_photos_per_event)
// to a small value so the boundary can be exercised without hundreds of uploads.
// The abuse cap default (500) does not interfere at these small limits.
class PhotoLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an UploadedFile from a committed fixture in test mode so it
     * bypasses is_uploaded_file(). gd is not required — the fixture is a real
     * image read via getimagesize(). The 1x1 sample.jpg is tiny so its bytes
     * never approach the Free plan's 500MB storage limit; this isolates the
     * photo-COUNT dimension (storage is exercised separately in 11.5).
     */
    private function fixtureFile(string $name = 'sample.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile(
            base_path("tests/Fixtures/{$name}"),
            'p.jpg',
            $mime,
            null,
            true // test mode
        );
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function fixtureFiles(int $n): array
    {
        $files = [];
        for ($i = 0; $i < $n; $i++) {
            $files[] = $this->fixtureFile();
        }

        return $files;
    }

    private function uploadableEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status'         => 'active',
            'upload_enabled' => true,
        ], $overrides));
    }

    private function upload(Event $event, array $photos)
    {
        // POST /e/{slug}/photos is throttle:uploads (10/min per IP). Clear the
        // throttle counters before each request so property/multi-request cases
        // never hit a spurious 429 that would mask the plan-limit behaviour.
        Cache::flush();

        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $photos]);
    }

    // Feature: saas-billing, Property 2: Photo-limit atomicity
    //
    // C + N <= M (1 existing + 2 incoming = 3 = M) → accepted: N photos stored,
    // N ProcessPhoto jobs dispatched.
    #[Test]
    public function within_limit_batch_is_accepted(): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['plans.free.max_photos_per_event' => 3]);

        $event = $this->uploadableEvent();
        Photo::factory()->for($event)->create(); // C = 1 (Free owner, no subscription)

        $response = $this->upload($event, $this->fixtureFiles(2)); // N = 2, C+N = 3 = M

        $response->assertRedirect("/e/{$event->slug}");
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertSame(3, $event->photos()->count());
        Queue::assertPushed(ProcessPhoto::class, 2);
    }

    // Feature: saas-billing, Property 2: Photo-limit atomicity
    //
    // C + N > M (2 existing + 2 incoming = 4 > 3) → 403 with the WHOLE batch
    // rejected: photo count unchanged, nothing dispatched, nothing stored.
    #[Test]
    public function over_limit_batch_is_rejected_atomically(): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['plans.free.max_photos_per_event' => 3]);

        $event = $this->uploadableEvent();
        Photo::factory()->count(2)->for($event)->create(); // C = 2

        $response = $this->upload($event, $this->fixtureFiles(2)); // N = 2, C+N = 4 > 3

        $response->assertForbidden();

        // Nothing stored: count still C, and no originals written to the disk.
        $this->assertSame(2, $event->photos()->count());
        Queue::assertNothingPushed();
        $this->assertEmpty(
            Storage::disk('public')->allFiles("events/{$event->uuid}/originals")
        );
    }

    // Feature: saas-billing, Property 2: Photo-limit atomicity
    //
    // A single large batch cannot bypass the plan limit by its size: with M = 3
    // and 0 existing photos, uploading 5 at once is rejected atomically — the
    // full incoming count is evaluated together, so it cannot sneak through.
    #[Test]
    public function batch_cannot_bypass_by_size(): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['plans.free.max_photos_per_event' => 3]);

        $event = $this->uploadableEvent(); // C = 0

        $response = $this->upload($event, $this->fixtureFiles(5)); // N = 5 > M = 3

        $response->assertForbidden();
        $this->assertSame(0, $event->photos()->count());
        Queue::assertNothingPushed();
        $this->assertEmpty(
            Storage::disk('public')->allFiles("events/{$event->uuid}/originals")
        );
    }

    // Feature: saas-billing, Property 2: Photo-limit atomicity
    //
    // Event isolation: the plan limit is scoped to the target event. Filling
    // event A to its limit does not affect event B (different owner). B still
    // accepts an upload within its own limit.
    #[Test]
    public function event_isolation(): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['plans.free.max_photos_per_event' => 3]);

        $eventA = $this->uploadableEvent();
        $eventB = $this->uploadableEvent(); // distinct event + owner

        // Fill A to its limit (C = 3 = M). Further uploads to A would be rejected.
        Photo::factory()->count(3)->for($eventA)->create();

        // A rejects (already at limit).
        $this->upload($eventA, $this->fixtureFiles(1))->assertForbidden();
        $this->assertSame(3, $eventA->photos()->count());

        // B is unaffected: it accepts an upload within its own limit.
        $response = $this->upload($eventB, $this->fixtureFiles(2)); // C=0, N=2 <= 3
        $response->assertRedirect("/e/{$eventB->slug}");
        $response->assertSessionHasNoErrors();
        $this->assertSame(2, $eventB->photos()->count());
        Queue::assertPushed(ProcessPhoto::class, 2);
    }

    // Feature: saas-billing, Property 2: Photo-limit atomicity
    //
    // Property (bounded loop): for random existing count C, incoming count N and
    // per-event limit M, BillingService::canUploadPhotos(owner, event, N, 0)
    // returns true iff C + N <= M. incomingBytes is passed as 0 to isolate the
    // photo-count dimension (storage is exercised separately in task 11.5).
    #[Test]
    public function photo_count_limit_holds_across_random_inputs(): void
    {
        $iterations = 40;

        for ($i = 0; $i < $iterations; $i++) {
            $c = random_int(0, 5);
            $n = random_int(1, 5);
            $m = random_int(1, 6);

            config(['plans.free.max_photos_per_event' => $m]);

            $owner = User::factory()->create(); // Free (no subscription)
            $event = Event::factory()->for($owner)->create(['status' => 'active']);
            if ($c > 0) {
                Photo::factory()->count($c)->for($event)->create();
            }

            $expected = ($c + $n) <= $m;

            $this->assertSame(
                $expected,
                app(BillingService::class)->canUploadPhotos($owner, $event, $n, 0),
                "canUploadPhotos must be (C + N <= M): C={$c}, N={$n}, M={$m}."
            );
        }
    }
}
