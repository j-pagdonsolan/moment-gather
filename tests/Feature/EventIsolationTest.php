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
 * Feature: security-rate-limiting — event isolation (IDOR) on the download endpoint.
 *
 * Route: GET /e/{slug}/photos/{photo}/download (public.events.photos.download).
 * The controller resolves the active event by slug first, then resolves the photo
 * by uuid *within that event's relationship* scoped to status=ready. Consequently a
 * photo belonging to event B can never be reached through event A's slug — resolution
 * is scoped through the event, never a global Photo query.
 *
 * This file adds the cross-event IDOR property that PhotoDownloadTest only covers with
 * a single example (download_photo_from_another_event_returns_404). It does not
 * duplicate the other download cases already asserted there.
 */
class EventIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function downloadUrl(string $slug, string $photo): string
    {
        return "/e/{$slug}/photos/{$photo}/download";
    }

    // Feature: security-rate-limiting, Property 2: Photos are reachable only through their own event (IDOR)
    //
    // For any two distinct active events A and B and any ready photo belonging to B
    // (with its file present on the fake public disk), requesting B's photo uuid under
    // A's slug returns HTTP 404 and never leaks B's data, while the legitimate request
    // for the same photo under B's own slug returns 200. This proves the isolation is
    // event-scoping, not blanket denial.
    // Requirements: 22.2, 2.1, 2.3, 2.4 — Property 2
    #[Test]
    public function photo_is_reachable_only_through_its_own_event(): void
    {
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            Storage::fake('public');

            // The download endpoint carries throttle:browse (60/min per IP). This
            // property test issues far more than 60 requests from the test client's
            // single IP, so flush the limiter store each iteration to isolate the
            // security behavior under test (event scoping) from rate limiting
            // (Property 6, covered separately in RateLimitTest).
            app('cache')->store(config('cache.default'))->flush();

            // Two DISTINCT active events. Randomized slugs (unique per iteration) and
            // uuids come naturally from the factory; force distinctness and active status.
            $eventA = Event::factory()->create([
                'status' => 'active',
                'slug'   => 'a-'.Str::lower(Str::random(12)).'-'.$i,
            ]);

            $eventB = Event::factory()->create([
                'status' => 'active',
                'slug'   => 'b-'.Str::lower(Str::random(12)).'-'.$i,
            ]);

            $this->assertNotSame($eventA->slug, $eventB->slug);
            $this->assertNotSame($eventA->id, $eventB->id);

            // Randomized photo attributes for B, bounded to the valid input space.
            $extension = ['jpg', 'jpeg', 'png', 'webp', 'gif'][random_int(0, 4)];
            $uuid = (string) Str::uuid();

            $photoB = Photo::factory()->for($eventB)->create([
                'status'            => Photo::STATUS_READY,
                'uuid'              => $uuid,
                'original_filename' => Str::random(random_int(3, 20)).'.'.$extension,
                'original_path'     => 'events/'.$eventB->uuid.'/originals/'.$uuid.'.'.$extension,
                'file_size'         => random_int(1000, 5_000_000),
                'width'             => random_int(100, 4000),
                'height'            => random_int(100, 4000),
            ]);

            // Real file on the fake public disk at the photo's own path.
            $bytes = 'secret-bytes-of-event-B-'.Str::random(16);
            Storage::disk('public')->put($photoB->original_path, $bytes);

            // IDOR attempt: B's photo uuid under A's slug MUST be 404 and leak nothing.
            $idor = $this->get($this->downloadUrl($eventA->slug, $photoB->uuid));
            $idor->assertNotFound();
            $idor->assertDontSee($bytes);
            $idor->assertDontSee($photoB->original_path);

            // Legitimate request: B's photo under B's own slug IS reachable → 200.
            $legit = $this->get($this->downloadUrl($eventB->slug, $photoB->uuid));
            $legit->assertOk();
            $this->assertSame($bytes, $legit->streamedContent());
        }
    }
}
