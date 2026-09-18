<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Property 6 coverage for the security-rate-limiting spec.
 *
 * Generalizes GuestPhotoUploadTest::excessive_requests_are_throttled (which
 * only covers the fixed uploads=10/min case) to: both limiters (uploads +
 * browse) over randomized small limits, a multi-file upload counting once,
 * and window recovery via Carbon time travel. The named limiters read their
 * limit from config at request time, so each case lowers the limit to a small
 * value for a deterministic, fast run. The test cache store is 'array' (see
 * phpunit.xml), so flushing the cache resets throttle counters between cases.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A committed image fixture in test mode (bypasses is_uploaded_file()).
     * Mirrors GuestPhotoUploadTest::fixtureFile so uploads pass validation.
     */
    private function fixtureFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'sample.jpg',
            'image/jpeg',
            null,
            true // test mode
        );
    }

    private function uploadableEvent(): Event
    {
        return Event::factory()->create([
            'status'         => 'active',
            'upload_enabled' => true,
        ]);
    }

    /**
     * Reset all limiter state. Throttle counters live in the (array) cache
     * store under this environment, so a flush clears every keyed counter.
     */
    private function resetLimiter(): void
    {
        Cache::flush();
    }

    /**
     * Issue one upload request from a fixed IP. A large per-event cap keeps
     * the photo cap from interfering with rate-limit counting.
     */
    private function upload(Event $event, string $ip, int $files = 1)
    {
        $photos = [];
        for ($i = 0; $i < $files; $i++) {
            $photos[] = $this->fixtureFile();
        }

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $photos]);
    }

    /**
     * Issue one browse request from a fixed IP. Sends Inertia XHR headers so
     * the controller returns a JSON/redirect response instead of a full Blade
     * render (which can 500 on a stale Vite manifest). A version mismatch
     * yields 409, a match yields 200 — both mean "not throttled".
     */
    private function browse(Event $event, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders([
                'X-Inertia'         => 'true',
                'X-Inertia-Version' => 'test',
                'X-Requested-With'  => 'XMLHttpRequest',
                'Accept'            => 'text/html, application/xhtml+xml',
            ])
            ->get("/e/{$event->slug}");
    }

    // Feature: security-rate-limiting, Property 6: Requests over the rate limit are rejected
    #[Test]
    public function requests_over_the_limit_are_rejected_for_both_limiters(): void
    {
        Storage::fake('public');
        Queue::fake();

        $iterations = 120; // >= 100 iterations across randomized limits/endpoints
        $ipCounter  = 0;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $limit    = random_int(1, 8);
            $isUpload = (bool) random_int(0, 1);
            $ip       = '10.0.'.intdiv($ipCounter, 250).'.'.($ipCounter % 250 + 1);
            $ipCounter++;

            $this->resetLimiter();

            if ($isUpload) {
                config(['uploads.rate_limit' => $limit, 'uploads.max_per_event' => 100000]);
                $event = $this->uploadableEvent();

                // The first L requests must NOT be throttled.
                for ($i = 0; $i < $limit; $i++) {
                    $status = $this->upload($event, $ip)->getStatusCode();
                    $this->assertNotSame(
                        429,
                        $status,
                        "Upload request #".($i + 1)." of {$limit} was throttled unexpectedly."
                    );
                }

                // Request L+1 must be throttled.
                $this->upload($event, $ip)->assertStatus(429);
            } else {
                config(['uploads.browse_rate_limit' => $limit]);
                $event = $this->uploadableEvent();

                for ($i = 0; $i < $limit; $i++) {
                    $status = $this->browse($event, $ip)->getStatusCode();
                    $this->assertNotSame(
                        429,
                        $status,
                        "Browse request #".($i + 1)." of {$limit} was throttled unexpectedly."
                    );
                }

                $this->browse($event, $ip)->assertStatus(429);
            }
        }
    }

    // Feature: security-rate-limiting, Property 6: a single multi-file upload
    // counts as exactly one request against the limit.
    #[Test]
    public function multi_file_upload_counts_as_a_single_request(): void
    {
        Storage::fake('public');
        Queue::fake();

        $limit = 3;
        config(['uploads.rate_limit' => $limit, 'uploads.max_per_event' => 100000]);
        $this->resetLimiter();

        $event = $this->uploadableEvent();
        $ip    = '10.1.1.1';

        // One request carrying 5 photos consumes exactly one hit and must not
        // itself be throttled.
        $this->assertNotSame(
            429,
            $this->upload($event, $ip, files: 5)->getStatusCode(),
            'The multi-file request should not be throttled.'
        );

        // After that single hit we can still make L-1 more single requests.
        for ($i = 0; $i < $limit - 1; $i++) {
            $this->assertNotSame(
                429,
                $this->upload($event, $ip)->getStatusCode(),
                "Single request #".($i + 1)." after the multi-file request was throttled early."
            );
        }

        // Now L requests have been consumed; the next one is throttled.
        $this->upload($event, $ip)->assertStatus(429);
    }

    // Feature: security-rate-limiting, Property 6: the rate-limit window resets
    // after a minute, allowing requests again (Carbon time travel, no sleep).
    #[Test]
    public function limiter_window_resets_after_a_minute(): void
    {
        Storage::fake('public');
        Queue::fake();

        $limit = 2;
        config(['uploads.rate_limit' => $limit, 'uploads.max_per_event' => 100000]);
        $this->resetLimiter();

        $event = $this->uploadableEvent();
        $ip    = '10.2.2.2';

        // Exhaust the limit and confirm the next request is throttled.
        for ($i = 0; $i < $limit; $i++) {
            $this->assertNotSame(429, $this->upload($event, $ip)->getStatusCode());
        }
        $this->upload($event, $ip)->assertStatus(429);

        // Advance past the one-minute window; the limiter must allow again.
        $this->travel(61)->seconds();

        $this->assertNotSame(
            429,
            $this->upload($event, $ip)->getStatusCode(),
            'Request after the window elapsed should not be throttled.'
        );

        $this->travelBack();
    }
}
