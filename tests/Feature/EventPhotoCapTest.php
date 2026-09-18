<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventPhotoCapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Synthesize a real image file with GD and wrap it as a test-mode
     * UploadedFile (bypasses is_uploaded_file). Real bytes so the upload
     * validator's `image`/`mimes` rules pass and getimagesize() succeeds.
     */
    private function makeImage(int $width = 8, int $height = 8, string $format = 'jpeg'): UploadedFile
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, 120, 90, 200));

        $tmp = tempnam(sys_get_temp_dir(), 'cap').".{$format}";
        match ($format) {
            'png'   => imagepng($gd, $tmp),
            'webp'  => imagewebp($gd, $tmp),
            default => imagejpeg($gd, $tmp, 90),
        };
        imagedestroy($gd);

        return new UploadedFile($tmp, "photo.{$format}", null, null, true);
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function makeImages(int $n): array
    {
        $files = [];
        for ($i = 0; $i < $n; $i++) {
            $files[] = $this->makeImage();
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
        // The upload route is throttled per IP (throttle:uploads). Property
        // iterations make many real POSTs, so clear the throttle counters
        // before each request to avoid a spurious 429 masking cap behavior.
        Cache::flush();

        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => $photos]);
    }

    // Feature: security-rate-limiting, Property 5: The event photo cap rejects exactly at the boundary
    //
    // For C existing non-deleted photos, N incoming files, and cap M:
    //   C + N > M  → HTTP 403, no new photos stored, no ProcessPhoto jobs dispatched.
    //   C + N <= M → accepted, N photos persisted (count = C + N), N jobs dispatched.
    // The boundary C + N == M is ACCEPTED; C + N == M + 1 is rejected.
    // Requirements: 22.11, 11.2, 11.3, 11.4 — Property 5
    #[Test]
    public function event_photo_cap_rejects_exactly_at_the_boundary(): void
    {
        $iterations = 120;

        // Deterministic boundary cases interleaved with randomized ones so the
        // exact edge (C+N == M accepted, C+N == M+1 rejected) is always exercised.
        for ($i = 0; $i < $iterations; $i++) {
            Storage::fake('public');
            Queue::fake();

            // Cap M kept small to keep the test fast.
            $maxPerEvent = random_int(3, 10);
            // Allow N incoming files up to the cap via the per-request validator.
            config([
                'uploads.max_per_event' => $maxPerEvent,
                'uploads.max_files'     => max($maxPerEvent + 1, 20),
            ]);

            // Force explicit boundary iterations.
            if ($i % 3 === 0) {
                // Exactly at the cap: C + N == M  → accepted.
                $c = random_int(0, $maxPerEvent - 1);
                $n = $maxPerEvent - $c;               // C + N == M
            } elseif ($i % 3 === 1) {
                // One over the cap: C + N == M + 1  → rejected.
                $c = random_int(0, $maxPerEvent);
                $n = ($maxPerEvent + 1) - $c;          // C + N == M + 1
            } else {
                // Fully randomized within the validator's file limit.
                $c = random_int(0, $maxPerEvent + 2);
                $n = random_int(1, $maxPerEvent + 1);
            }

            // Guard the generated values against the validator's own bounds so
            // that only the cap (not max_files or empty array) drives behavior.
            $n = max(1, min($n, (int) config('uploads.max_files')));

            $event = $this->uploadableEvent();
            if ($c > 0) {
                Photo::factory()->count($c)->create(['event_id' => $event->id]);
            }

            $response = $this->upload($event, $this->makeImages($n));

            $overCap = ($c + $n) > $maxPerEvent;

            if ($overCap) {
                $response->assertForbidden();
                // No new photos stored; count stays at C.
                $this->assertSame(
                    $c,
                    $event->photos()->count(),
                    "Over cap (C={$c}, N={$n}, M={$maxPerEvent}): photo count must stay at C."
                );
                Queue::assertNothingPushed();
            } else {
                $response->assertRedirect("/e/{$event->slug}");
                $response->assertSessionHasNoErrors();
                // N new photos persisted; count becomes C + N.
                $this->assertSame(
                    $c + $n,
                    $event->photos()->count(),
                    "Within cap (C={$c}, N={$n}, M={$maxPerEvent}): photo count must be C + N."
                );
                Queue::assertPushed(ProcessPhoto::class, $n);
            }
        }
    }

    // Feature: security-rate-limiting, Property 5: The event photo cap rejects exactly at the boundary
    //
    // Explicit boundary assertion: with C + N == M the upload is accepted, and
    // with the same C but one extra file (C + N == M + 1) it is rejected.
    // Requirements: 11.2, 11.3 — Property 5
    #[Test]
    public function boundary_is_accepted_at_cap_and_rejected_one_over(): void
    {
        $maxPerEvent = 5;
        config([
            'uploads.max_per_event' => $maxPerEvent,
            'uploads.max_files'     => 20,
        ]);

        // C + N == M → accepted.
        Storage::fake('public');
        Queue::fake();
        $accepted = $this->uploadableEvent();
        Photo::factory()->count(3)->create(['event_id' => $accepted->id]); // C = 3
        $this->upload($accepted, $this->makeImages(2))                      // N = 2, C+N = 5 = M
            ->assertRedirect("/e/{$accepted->slug}")
            ->assertSessionHasNoErrors();
        $this->assertSame(5, $accepted->photos()->count());
        Queue::assertPushed(ProcessPhoto::class, 2);

        // C + N == M + 1 → rejected.
        Storage::fake('public');
        Queue::fake();
        $rejected = $this->uploadableEvent();
        Photo::factory()->count(3)->create(['event_id' => $rejected->id]);  // C = 3
        $this->upload($rejected, $this->makeImages(3))                       // N = 3, C+N = 6 = M+1
            ->assertForbidden();
        $this->assertSame(3, $rejected->photos()->count());
        Queue::assertNothingPushed();
    }

    // Feature: security-rate-limiting, Property 5: The event photo cap rejects exactly at the boundary
    //
    // Soft-deleted photos are excluded from C (the cap is computed from
    // $event->photos()->count(), which the SoftDeletes global scope filters).
    // Requirements: 11.2, 11.4 — Property 5
    #[Test]
    public function soft_deleted_photos_do_not_count_toward_the_cap(): void
    {
        $maxPerEvent = 4;
        config([
            'uploads.max_per_event' => $maxPerEvent,
            'uploads.max_files'     => 20,
        ]);

        Storage::fake('public');
        Queue::fake();
        $event = $this->uploadableEvent();

        // 3 live photos + 5 soft-deleted photos. Only the 3 live ones count.
        Photo::factory()->count(3)->create(['event_id' => $event->id]);
        $deleted = Photo::factory()->count(5)->create(['event_id' => $event->id]);
        foreach ($deleted as $photo) {
            $photo->delete();
        }

        // Sanity: relationship count excludes soft-deleted rows.
        $this->assertSame(3, $event->photos()->count());

        // C = 3 live, N = 1 → C + N = 4 = M → accepted despite 5 soft-deleted rows.
        $this->upload($event, $this->makeImages(1))
            ->assertRedirect("/e/{$event->slug}")
            ->assertSessionHasNoErrors();

        $this->assertSame(4, $event->photos()->count());
        Queue::assertPushed(ProcessPhoto::class, 1);
    }

    // Feature: security-rate-limiting, Property 5: The event photo cap rejects exactly at the boundary
    //
    // On a 403 cap rejection the controller emits a sanitized warning log
    // carrying only slug + counts (no filenames or file contents).
    // Requirements: 21.2 — Property 5
    #[Test]
    public function cap_rejection_emits_sanitized_warning_log(): void
    {
        $maxPerEvent = 2;
        config([
            'uploads.max_per_event' => $maxPerEvent,
            'uploads.max_files'     => 20,
        ]);

        Storage::fake('public');
        Queue::fake();
        Log::spy();

        $event = $this->uploadableEvent();
        Photo::factory()->count(2)->create(['event_id' => $event->id]); // C = 2 = M

        // C + N = 3 > M → rejected.
        $this->upload($event, $this->makeImages(1))->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($event, $maxPerEvent) {
                if ($message !== 'Upload rejected: event photo cap exceeded') {
                    return false;
                }

                // Context is limited to sanitized, non-sensitive fields.
                $this->assertSame(
                    ['event_slug', 'current_count', 'incoming', 'max_per_event'],
                    array_keys($context)
                );
                $this->assertSame($event->slug, $context['event_slug']);
                $this->assertSame(2, $context['current_count']);
                $this->assertSame(1, $context['incoming']);
                $this->assertSame($maxPerEvent, $context['max_per_event']);

                // No filename / raw file content leaks into the context.
                $encoded = json_encode($context);
                $this->assertStringNotContainsString('photo.jpg', $encoded);
                $this->assertStringNotContainsString('tmp', $encoded);

                return true;
            });

        // No photos stored on rejection.
        $this->assertSame(2, $event->photos()->count());
    }
}
