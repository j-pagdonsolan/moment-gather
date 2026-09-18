<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 security coverage for the guest upload endpoint.
 *
 * This file ADDS only the adversarial/security cases that GuestPhotoUploadTest
 * and ImageProcessingTest do not already cover: path-traversal + mass-assignment
 * injection across many randomized inputs (Property 4), server-side rejection of
 * invalid uploads across the three rejection modes (Property 7), a .php script
 * payload example, CSRF protection of the upload route, and the sanitized
 * validation-failure log. It intentionally does NOT duplicate the happy-path,
 * per-format, or basic single-case rejection tests that already exist.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum iterations for every property test in this feature. */
    private const ITERATIONS = 120;

    private function activeUploadableEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status'         => 'active',
            'upload_enabled' => true,
        ], $overrides));
    }

    /**
     * Synthesize a real, decodable image with GD and wrap it as a test-mode
     * UploadedFile (bypasses is_uploaded_file). Real bytes so the `image`
     * validation rule and getimagesize() succeed. The client filename is
     * fully controlled by the caller so adversarial names can be injected.
     */
    private function makeImage(
        int $width,
        int $height,
        string $format,
        string $clientName
    ): UploadedFile {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle(
            $gd,
            0,
            0,
            $width - 1,
            $height - 1,
            imagecolorallocate($gd, random_int(0, 255), random_int(0, 255), random_int(0, 255))
        );
        $tmp = tempnam(sys_get_temp_dir(), 'usec') . ".{$format}";
        match ($format) {
            'png'  => imagepng($gd, $tmp),
            'webp' => imagewebp($gd, $tmp),
            default => imagejpeg($gd, $tmp, 90),
        };
        imagedestroy($gd);

        // null mime → let the framework/UploadedFile infer it, matching real uploads.
        return new UploadedFile($tmp, $clientName, null, null, true);
    }

    /**
     * Reset the throttle:uploads counters. The throttle middleware stores its
     * per-key hit counts in the cache-backed limiter, so flushing the cache
     * clears them. This keeps the property loop from tripping the 10/min limit;
     * the limiter itself is exercised in RateLimitTest.
     */
    private function clearUploadThrottle(): void
    {
        cache()->flush();
    }

    private function upload(Event $event, array $photos, array $extra = [])
    {
        return $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", array_merge(['photos' => $photos], $extra));
    }

    // Feature: security-rate-limiting, Property 4: Stored paths are server-generated and client fields are ignored
    #[Test]
    public function stored_paths_are_server_generated_and_client_fields_ignored(): void
    {
        $formats    = ['jpeg', 'jpg', 'png', 'webp'];
        $traversals = [
            '../../evil.jpg',
            '..\\..\\evil.png',
            '../../../etc/passwd.webp',
            '....//....//evil.jpeg',
            '%2e%2e%2fevil.jpg',
            'a/b/../../../../evil.png',
            'foo/../../../evil.webp',
            'normal name with spaces.jpg',
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            Storage::fake('public');
            Queue::fake();
            // Reset the per-IP upload throttle so the iteration loop itself never
            // trips the 10/min limiter (rate limiting is covered by RateLimitTest).
            $this->clearUploadThrottle();
            // Start each iteration from a clean photos table so counts are exact.
            Photo::withTrashed()->forceDelete();

            // Fresh events each iteration: `target` receives the upload, `other`
            // exists only so its id can be injected as an adversarial event_id.
            $target = $this->activeUploadableEvent();
            $other  = $this->activeUploadableEvent();

            $format     = $formats[array_rand($formats)];
            $traversal  = $traversals[array_rand($traversals)];
            $width      = random_int(1, 64);
            $height     = random_int(1, 64);

            // Extension used by the server is derived from the client name; when
            // the adversarial name has no usable extension the encoder format wins.
            $file = $this->makeImage($width, $height, $format, $traversal);

            $response = $this->upload($target, [$file], [
                // Mass-assignment injection: none of these must influence storage.
                'event_id'      => $other->id,
                'status'        => 'ready',
                'original_path' => '../../evil.jpg',
            ]);

            $response->assertRedirect("/e/{$target->slug}");
            $response->assertSessionHasNoErrors();

            $this->assertSame(1, Photo::count(), "Iteration {$i}: exactly one photo expected.");
            $photo = Photo::firstOrFail();

            // event_id resolves from the slug, never the injected client value.
            $this->assertSame($target->id, $photo->event_id, "Iteration {$i}: event_id must resolve from slug.");
            $this->assertNotSame($other->id, $photo->event_id, "Iteration {$i}: injected event_id must be ignored.");

            // status is the server-set pending state, never the injected 'ready'.
            $this->assertSame(Photo::STATUS_PENDING, $photo->status, "Iteration {$i}: status must be server pending.");

            // original_path is server-generated within the resolved event's area,
            // named by the photo uuid — never influenced by the traversal name or
            // the injected original_path.
            $this->assertMatchesRegularExpression(
                '#^events/' . preg_quote($target->uuid, '#') . '/originals/[0-9a-f-]{36}\.[a-z0-9]+$#i',
                $photo->original_path,
                "Iteration {$i}: original_path must be server-generated (name={$traversal})."
            );
            $this->assertStringNotContainsString('..', $photo->original_path, "Iteration {$i}: no traversal in path.");
            $this->assertStringNotContainsString('evil', $photo->original_path, "Iteration {$i}: adversarial name must not leak.");
            $this->assertSame("{$photo->uuid}." . pathinfo($photo->original_path, PATHINFO_EXTENSION), basename($photo->original_path));

            // The stored file actually lives at the server path.
            Storage::disk('public')->assertExists($photo->original_path);
        }
    }

    // Feature: security-rate-limiting, Property 7: Invalid uploads are rejected server-side
    #[Test]
    public function invalid_uploads_are_rejected_server_side(): void
    {
        $maxFiles  = (int) config('uploads.max_files');
        $maxFileKb = (int) config('uploads.max_file_kb');

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            Storage::fake('public');
            Queue::fake();
            $this->clearUploadThrottle();
            Photo::withTrashed()->forceDelete();
            $event = $this->activeUploadableEvent();

            // Randomly select one of the three server-side rejection modes.
            $mode = ['unsupported', 'oversized', 'overcount'][random_int(0, 2)];

            $payload = match ($mode) {
                // (a) unsupported format: a non-image or wrong type, chosen randomly.
                'unsupported' => [$this->unsupportedFile($i)],
                // (b) oversized: a fake file just over the KB limit.
                'oversized' => [
                    UploadedFile::fake()->create(
                        'big.jpg',
                        $maxFileKb + random_int(1, 512),
                        'image/jpeg'
                    ),
                ],
                // (c) over-count: more than max_files valid items.
                'overcount' => $this->manyValidFiles($maxFiles + random_int(1, 3)),
            };

            $response = $this->postJson("/e/{$event->slug}/photos", ['photos' => $payload]);

            $response->assertStatus(422);
            $this->assertSame(0, Photo::count(), "Iteration {$i} ({$mode}): no photo may persist.");
            $this->assertEmpty(Storage::disk('public')->allFiles(), "Iteration {$i} ({$mode}): nothing stored.");
            Queue::assertNothingPushed();
        }
    }

    /**
     * Produce an invalid (non-supported) upload, randomized between a genuine
     * text file, a spoofed .gif, and non-image bytes carrying an image name/mime.
     */
    private function unsupportedFile(int $seed): UploadedFile
    {
        return match ($seed % 3) {
            0 => UploadedFile::fake()->create('notes.txt', random_int(1, 20), 'text/plain'),
            1 => UploadedFile::fake()->create('anim.gif', random_int(1, 20), 'image/gif'),
            default => new UploadedFile(
                base_path('tests/Fixtures/not-an-image.jpg'),
                'looks-like.jpg',
                'image/jpeg',
                null,
                true
            ),
        };
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function manyValidFiles(int $count): array
    {
        $files = [];
        for ($j = 0; $j < $count; $j++) {
            $files[] = $this->makeImage(8, 8, 'jpeg', "valid-{$j}.jpg");
        }

        return $files;
    }

    // Feature: security-rate-limiting, Property 7 (example): a .php script payload
    // spoofing an image mime is rejected with 422 and no photo is stored.
    #[Test]
    public function php_script_payload_is_rejected(): void
    {
        Storage::fake('public');
        Queue::fake();
        $event = $this->activeUploadableEvent();

        // A PHP script with a .php name and a spoofed image content-type header.
        $script = new UploadedFile(
            base_path('tests/Fixtures/not-an-image.jpg'), // non-image bytes on disk
            'shell.php',
            'image/jpeg',
            null,
            true
        );

        $response = $this->postJson("/e/{$event->slug}/photos", ['photos' => [$script]]);

        $response->assertStatus(422);
        $this->assertSame(0, Photo::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    // Feature: security-rate-limiting: the upload route is CSRF-protected.
    //
    // Laravel disables CSRF verification while running tests (PreventRequestForgery
    // short-circuits on runningUnitTests()), so posting without a token in a feature
    // test cannot yield a real 419 — a "419 test" here would be a no-op/false pass.
    // Instead we exercise the actual protection meaningfully: the POST route runs
    // through the `web` group which includes the CSRF middleware, and the upload
    // path is NOT present in any CSRF exclusion list. If the route were moved out
    // of `web` or added to an except list, these assertions fail.
    #[Test]
    public function upload_route_is_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('public.events.photos.store');

        $this->assertNotNull($route, 'The upload route must be registered.');
        $this->assertContains('POST', $route->methods(), 'Upload must be a state-changing POST.');

        // The route runs through the `web` middleware group (route:list shows the
        // `web` group applied to routes/web.php routes).
        $this->assertContains('web', $route->gatherMiddleware(), 'Upload route must be in the web group.');

        // The resolved `web` group (from the HTTP kernel) contains the CSRF
        // middleware, so a tokenless cross-site POST to this route is verified
        // (and rejected) in production.
        $webGroup = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->getMiddlewareGroups()['web'] ?? [];
        $this->assertContains(
            PreventRequestForgery::class,
            $webGroup,
            'The web group must include CSRF middleware.'
        );

        // The upload path is not excluded from CSRF verification (no except entry,
        // no global neverVerify entry). getExcludedPaths() merges both.
        $csrf = $this->app->make(PreventRequestForgery::class);
        $excluded = $csrf->getExcludedPaths();

        foreach ($excluded as $pattern) {
            $this->assertStringNotContainsString(
                'photos',
                (string) $pattern,
                'Upload path must not be excluded from CSRF verification.'
            );
        }
        $this->assertNotContains("e/*/photos", $excluded);
        $this->assertNotContains("e/{slug}/photos", $excluded);
    }

    // Feature: security-rate-limiting: an invalid upload emits a sanitized
    // Log::warning('Upload validation failed', ...) whose context contains no
    // filename and no file contents — only event_slug, file_count, first_error.
    #[Test]
    public function invalid_upload_logs_sanitized_warning(): void
    {
        Storage::fake('public');
        Queue::fake();
        Log::spy();

        $event = $this->activeUploadableEvent();

        $secretName = 'my-private-vacation-photo-DO-NOT-LOG.txt';
        $file = UploadedFile::fake()->create($secretName, 5, 'text/plain');

        $this->postJson("/e/{$event->slug}/photos", ['photos' => [$file]])
            ->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($event, $secretName) {
                if ($message !== 'Upload validation failed') {
                    return false;
                }

                // Only the sanitized keys are present.
                $this->assertEqualsCanonicalizing(
                    ['event_slug', 'file_count', 'first_error'],
                    array_keys($context),
                    'Log context must contain only sanitized keys.'
                );

                $this->assertSame($event->slug, $context['event_slug']);
                $this->assertSame(1, $context['file_count']);
                $this->assertSame('photos.0', $context['first_error']);

                // No filename or raw contents anywhere in the serialized context.
                $encoded = json_encode($context);
                $this->assertStringNotContainsString($secretName, $encoded, 'Filename must not be logged.');
                $this->assertStringNotContainsString('DO-NOT-LOG', $encoded, 'Filename fragment must not be logged.');

                return true;
            });
    }
}
