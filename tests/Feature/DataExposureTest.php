<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: security-rate-limiting
 *
 * Property 3: Public responses expose no internal identifiers or paths.
 *
 * Every Public_Endpoint response (event page, gallery) exposes only the
 * permitted display fields and never a raw storage-path segment, database
 * primary/foreign key, event uuid, user_id, or internal timestamp. Error
 * bodies (404/403) likewise carry no filesystem path or SQL fragment.
 *
 * The public gallery legitimately exposes URLs built via Storage::url, which
 * contain the PUBLIC "/storage/..." prefix — that is allowed. What must never
 * leak is the RAW relative storage path stored in the DB
 * (e.g. "events/{uuid}/originals/{uuid}.jpg"). The assertions below are
 * deliberately precise about that distinction.
 *
 * Testing notes:
 * - Pages are fetched as Inertia XHR requests (X-Inertia header) so the
 *   response is the raw props JSON. This inspects the exact data crossing to
 *   the frontend AND avoids the stale-Vite-manifest error that a full Blade
 *   render of Public/Event.tsx / Public/Gallery.tsx hits in this environment.
 *   No X-Inertia-Version header is sent, so Inertia never returns a 409
 *   version-conflict redirect.
 * - Each iteration uses a distinct client IP (REMOTE_ADDR) so the browse/upload
 *   rate limiters (60/min, 10/min, keyed by IP) never trip across the
 *   >=100-iteration bounded loop.
 */
class DataExposureTest extends TestCase
{
    use RefreshDatabase;

    private ?string $inertiaVersion = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The Inertia middleware computes the asset version from the Vite
        // manifest hash; capture it so XHR requests can echo it back and avoid
        // a 409 version-conflict.
        $this->inertiaVersion = (new \App\Http\Middleware\HandleInertiaRequests())
            ->version(request());
    }

    /**
     * A distinct client IP per iteration keeps each request under the per-IP
     * rate limit, so the throttle middleware never returns a 429 inside the
     * bounded property loop.
     */
    private function ipForIteration(int $i): array
    {
        return ['REMOTE_ADDR' => '10.'.intdiv($i, 65025).'.'.(intdiv($i, 255) % 255).'.'.(($i % 255) + 1)];
    }

    /**
     * Fetch a page as an Inertia XHR request and return the props-JSON body.
     * No version header → no 409 version-conflict.
     */
    private function getInertia(string $uri, int $i)
    {
        // Send the server's own asset version so an XHR request is not answered
        // with a 409 version-conflict (which would force a full page reload).
        $headers = ['X-Inertia' => 'true'];

        if ($this->inertiaVersion !== null) {
            $headers['X-Inertia-Version'] = $this->inertiaVersion;
        }

        return $this->withServerVariables($this->ipForIteration($i))
            ->get($uri, $headers);
    }

    /**
     * Substrings that, if present verbatim in a public response body, would
     * constitute a leak of an internal storage location.
     *
     * @return array<int, string>
     */
    private function forbiddenPathSegments(string $eventUuid): array
    {
        return [
            "events/{$eventUuid}/originals",
            "events/{$eventUuid}/optimized",
            "events/{$eventUuid}/thumbnails",
        ];
    }

    /**
     * Assert the serialized body carries none of the forbidden markers for the
     * given event and its photos.
     *
     * @param  iterable<\App\Models\Photo>  $photos
     */
    private function assertBodyHasNoLeaks(string $body, Event $event, iterable $photos): void
    {
        // The gallery legitimately serves photo variants as PUBLIC URLs built
        // by Storage::url — e.g. "/storage/events/{event_uuid}/thumbnails/…".
        // Those public URLs are allowed, but they embed the event uuid and the
        // raw path segment as substrings. To be precise, strip every public
        // "/storage/…" URL out of the body first; then any raw path, uuid, or
        // id that remains is a genuine leak (it appeared OUTSIDE a public URL).
        $residual = $this->stripPublicStorageUrls($body);

        // Raw storage-path segments must never appear outside a public URL.
        foreach ($this->forbiddenPathSegments($event->uuid) as $segment) {
            $this->assertStringNotContainsString(
                $segment,
                $residual,
                "Raw storage path segment leaked (outside a public /storage URL): {$segment}"
            );
        }

        // The event's internal identifiers must never appear outside a public
        // URL (the uuid is permitted only inside the /storage variant URLs).
        $this->assertStringNotContainsString($event->uuid, $residual, 'Event uuid leaked');
        $this->assertStringNotContainsString('"user_id"', $body, 'user_id key leaked');
        $this->assertStringNotContainsString('"event_id"', $body, 'event_id key leaked');
        $this->assertStringNotContainsString('"id"', $body, 'db id key leaked');

        // Internal timestamp keys must never appear.
        $this->assertStringNotContainsString('created_at', $body, 'created_at leaked');
        $this->assertStringNotContainsString('updated_at', $body, 'updated_at leaked');
        $this->assertStringNotContainsString('deleted_at', $body, 'deleted_at leaked');

        // Per-photo raw DB path values (the relative column value, not the
        // public URL) must never appear outside a public URL.
        foreach ($photos as $photo) {
            $this->assertStringNotContainsString($photo->original_path, $residual, 'original_path leaked');

            if ($photo->optimized_path !== null) {
                $this->assertStringNotContainsString($photo->optimized_path, $residual, 'optimized_path leaked');
            }

            if ($photo->thumbnail_path !== null) {
                $this->assertStringNotContainsString($photo->thumbnail_path, $residual, 'thumbnail_path leaked');
            }
        }
    }

    /**
     * Remove every permitted public "/storage/…" URL token from the body so
     * the remaining text can be checked for raw paths / ids that leaked
     * OUTSIDE an allowed public URL. Public storage URLs are the only sanctioned
     * place an event uuid or a raw-looking path segment may appear.
     */
    private function stripPublicStorageUrls(string $body): string
    {
        // A public storage URL token runs from "/storage" up to the closing
        // JSON double-quote. In the JSON body slashes are escaped ("\/"), so
        // first normalize "\/" to "/", then remove each "/storage/..." run up
        // to the next quote. Whatever remains never sat inside a public URL.
        $normalized = str_replace('\\/', '/', $body);

        return (string) preg_replace('#/storage/[^"]*#', '', $normalized);
    }

    // Feature: security-rate-limiting, Property 3: Public responses expose no
    // internal identifiers or paths
    //
    // For a randomized active event with a randomized mix of ready and
    // non-ready photos, the event-page response exposes only the permitted
    // display fields (slug, name) and carries none of the leakage markers.
    #[Test]
    public function event_page_exposes_no_internal_identifiers_or_paths(): void
    {
        Storage::fake('public');

        // >= 100 iterations over randomized events + photo sets (bounded loop
        // sampling the input space; per testing-approach.md fallback).
        for ($i = 0; $i < 100; $i++) {
            $event = Event::factory()->create([
                'status' => 'active',
                'slug'   => 'evt-'.Str::lower(Str::random(12)).'-'.$i,
            ]);

            $photos = $this->makeRandomPhotoSet($event);

            $response = $this->getInertia("/e/{$event->slug}", $i);
            $response->assertOk();

            $body = $response->getContent();
            $page = json_decode($body, true);

            // Valid Inertia page for the expected component.
            $this->assertIsArray($page, 'event page should return the Inertia page JSON');
            $this->assertSame('Public/Event', $page['component'] ?? null);

            // Permitted display fields ARE present with their DB values.
            $eventProps = $page['props']['event'] ?? [];
            $this->assertSame($event->slug, $eventProps['slug'] ?? null);
            $this->assertSame($event->name, $eventProps['name'] ?? null);

            // Forbidden fields are absent from the event payload.
            foreach (['id', 'uuid', 'user_id', 'created_at', 'updated_at', 'deleted_at'] as $forbidden) {
                $this->assertArrayNotHasKey(
                    $forbidden,
                    $eventProps,
                    "event payload must not expose {$forbidden}"
                );
            }

            // The serialized body (Inertia props JSON) leaks nothing.
            $this->assertBodyHasNoLeaks($body, $event, $photos);

            // Cleanup so the next iteration starts clean and stays cheap.
            Photo::query()->forceDelete();
            $event->forceDelete();
        }
    }

    // Feature: security-rate-limiting, Property 3: Public responses expose no
    // internal identifiers or paths
    //
    // For a randomized active event with a randomized mix of ready and
    // non-ready photos, the gallery response exposes gallery display fields
    // (photo uuid + URLs) but never a RAW storage path, db id, event uuid,
    // user_id, or timestamp. The permitted public "/storage/..." URL is not
    // treated as a leak; the raw "events/{uuid}/originals/..." path is.
    #[Test]
    public function gallery_exposes_no_internal_identifiers_or_paths(): void
    {
        Storage::fake('public');

        for ($i = 0; $i < 100; $i++) {
            $event = Event::factory()->create([
                'status' => 'active',
                'slug'   => 'gal-'.Str::lower(Str::random(12)).'-'.$i,
            ]);

            $photos = $this->makeRandomPhotoSet($event);

            $response = $this->getInertia("/e/{$event->slug}/gallery", $i);
            $response->assertOk();

            $body = $response->getContent();

            $this->assertBodyHasNoLeaks($body, $event, $photos);

            // Sanity: the gallery still carries permitted display fields — the
            // event slug/name and, for any ready photo, its uuid.
            $this->assertStringContainsString($event->slug, $body, 'gallery should expose the slug');
            $this->assertStringContainsString($event->name, $body, 'gallery should expose the name');

            foreach ($photos as $photo) {
                if ($photo->status === Photo::STATUS_READY) {
                    $this->assertStringContainsString(
                        $photo->uuid,
                        $body,
                        'gallery should expose the ready photo uuid'
                    );
                }
            }

            Photo::query()->forceDelete();
            $event->forceDelete();
        }
    }

    // Feature: security-rate-limiting, Property 3: a 404 response body (for a
    // nonexistent slug) carries no filesystem path or SQL fragment.
    #[Test]
    public function not_found_body_leaks_no_path_or_sql(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $slug = 'missing-'.Str::lower(Str::random(16)).'-'.$i;

            $response = $this->withServerVariables($this->ipForIteration($i))
                ->get("/e/{$slug}");

            $response->assertNotFound();
            $this->assertBodyHasNoInfrastructureLeak($response->getContent());
        }
    }

    // Feature: security-rate-limiting, Property 3: a 403 response body (uploads
    // closed / cap) carries no filesystem path or SQL fragment.
    #[Test]
    public function forbidden_body_leaks_no_path_or_sql(): void
    {
        Storage::fake('public');

        for ($i = 0; $i < 100; $i++) {
            // Uploads-closed 403: an active event with upload_enabled = false.
            $event = Event::factory()->create([
                'status'         => 'active',
                'upload_enabled' => false,
                'slug'           => 'closed-'.Str::lower(Str::random(12)).'-'.$i,
            ]);

            $response = $this->withServerVariables($this->ipForIteration($i))
                ->from("/e/{$event->slug}")
                ->post("/e/{$event->slug}/photos", [
                    'photos' => [UploadedFile::fake()->image('x.jpg')],
                ]);

            $response->assertForbidden();
            $this->assertBodyHasNoInfrastructureLeak($response->getContent());

            $event->forceDelete();
        }
    }

    /**
     * Create a randomized set of photos (mix of ready and non-ready) for an
     * event, with real relative storage paths under the event's uuid so the
     * leak assertions have concrete verbatim values to look for.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Photo>
     */
    private function makeRandomPhotoSet(Event $event)
    {
        $count = random_int(0, 5);
        $statuses = [
            Photo::STATUS_READY,
            Photo::STATUS_PENDING,
            Photo::STATUS_PROCESSING,
            Photo::STATUS_FAILED,
        ];

        $photos = collect();

        for ($p = 0; $p < $count; $p++) {
            $status = $statuses[array_rand($statuses)];
            $uuid   = (string) Str::uuid();

            $photo = Photo::factory()->for($event)->create([
                'uuid'          => $uuid,
                'status'        => $status,
                'original_path' => "events/{$event->uuid}/originals/{$uuid}.jpg",
            ]);

            // Give ready photos processed variants so their paths are also
            // exercised by the leak assertions.
            if ($status === Photo::STATUS_READY && random_int(0, 1) === 1) {
                $photo->update([
                    'optimized_path' => "events/{$event->uuid}/optimized/{$uuid}.webp",
                    'thumbnail_path' => "events/{$event->uuid}/thumbnails/{$uuid}.webp",
                ]);
                $photo->refresh();
            }

            $photos->push($photo);
        }

        return $photos;
    }

    /**
     * Assert an error body contains no filesystem path or SQL fragment. Uses
     * generic infrastructure markers rather than event-specific ones, since
     * the leak surface for a generic error is a stack trace / query dump.
     */
    private function assertBodyHasNoInfrastructureLeak(string $body): void
    {
        $needles = [
            'events/',              // raw storage path segment
            '/originals',
            '/optimized',
            '/thumbnails',
            'C:\\xampp',            // absolute filesystem path (this environment)
            'app/Http/Controllers', // source-tree path from a stack trace
            'vendor/laravel',
            'select * from',        // SQL fragment
            'SQLSTATE',
            'PDOException',
        ];

        foreach ($needles as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $body,
                "Error body leaked infrastructure detail: {$needle}"
            );
        }
    }
}
