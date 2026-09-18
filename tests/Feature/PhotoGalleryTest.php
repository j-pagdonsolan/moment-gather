<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhotoGalleryTest extends TestCase
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

    // ---- Property 1: Gallery visibility soundness and completeness ----

    // Property 1: an active, non-deleted event resolves and renders the
    // gallery for an unauthenticated guest. Requirements 1.2, 1.6
    #[Test]
    public function active_event_gallery_is_accessible_without_auth(): void
    {
        $event = $this->activeEvent(['slug' => 'active-gallery']);

        // No actingAs() — request made as a guest.
        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Gallery')
                ->where('event.slug', 'active-gallery')
                ->where('event.name', $event->name)
            );
    }

    // Property 1: a slug matching no event resolves to HTTP 404. Requirement 1.3
    #[Test]
    public function invalid_slug_returns_404(): void
    {
        $this->get('/e/nope/gallery')->assertNotFound();
    }

    // Property 1: a non-active (draft) event is never public. Requirement 1.4
    #[Test]
    public function draft_event_gallery_returns_404(): void
    {
        $event = $this->activeEvent(['status' => 'draft', 'slug' => 'draft-gallery']);

        $this->get("/e/{$event->slug}/gallery")->assertNotFound();
    }

    // Property 1: a non-active (archived) event is never public. Requirement 1.4
    #[Test]
    public function archived_event_gallery_returns_404(): void
    {
        $event = $this->activeEvent(['status' => 'archived', 'slug' => 'archived-gallery']);

        $this->get("/e/{$event->slug}/gallery")->assertNotFound();
    }

    // Property 1: a soft-deleted event is excluded and resolves to 404.
    // Requirement 1.5
    #[Test]
    public function soft_deleted_event_gallery_returns_404(): void
    {
        $event = $this->activeEvent(['slug' => 'deleted-gallery']);
        $event->delete(); // soft delete

        $this->get("/e/{$event->slug}/gallery")->assertNotFound();
    }

    // ---- Property 2: Ready-and-event scoping of gallery photos ----

    // Property 2: only the target event's ready, non-deleted photos appear;
    // pending/processing/failed/deleted photos and another event's photos are
    // excluded. Requirements 2.1, 2.2, 2.3, 2.4, 2.5
    #[Test]
    public function only_ready_photos_of_this_event_appear(): void
    {
        Storage::fake('public');

        $eventA = $this->activeEvent(['slug' => 'event-a']);

        $ready1 = $this->readyPhoto($eventA);
        $ready2 = $this->readyPhoto($eventA);
        $this->readyPhoto($eventA, ['status' => 'pending']);
        $this->readyPhoto($eventA, ['status' => 'failed']);
        $this->readyPhoto($eventA, ['status' => 'processing']);
        $this->readyPhoto($eventA, ['status' => 'deleted']);

        $eventB = $this->activeEvent(['slug' => 'event-b']);
        $bReady = $this->readyPhoto($eventB);

        $expected = [$ready1->uuid, $ready2->uuid];

        $this->get("/e/{$eventA->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($expected, $bReady) {
                $page->component('Public/Gallery')->has('photos', 2);

                $uuids = collect($page->toArray()['props']['photos'])
                    ->pluck('uuid')
                    ->all();

                sort($uuids);
                $expectedSorted = $expected;
                sort($expectedSorted);

                $this->assertSame($expectedSorted, $uuids);
                $this->assertNotContains($bReady->uuid, $uuids);
            });
    }

    // ---- Property 3: Payload minimization and Display URL form ----

    // Property 3: each item exposes only the safe fields; database id,
    // event_id, and the raw storage path never cross the boundary, and url is
    // the public /storage form. Requirements 3.1, 3.2, 3.3, 3.4, 3.5
    #[Test]
    public function gallery_payload_exposes_only_safe_fields(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'payload-check']);
        $photo = $this->readyPhoto($event);

        // Put a real file so the URLs resolve to /storage paths.
        Storage::disk('public')->put($photo->original_path, 'x');

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Public/Gallery')
                    ->has('photos', 1)
                    ->has('photos.0', fn (AssertableInertia $item) => $item
                        ->has('uuid')
                        ->has('thumbnailUrl')
                        ->has('optimizedUrl')
                        ->has('filename')
                        ->has('width')
                        ->has('height')
                        ->missing('url')
                        ->missing('mime_type')
                        ->missing('id')
                        ->missing('event_id')
                        ->missing('original_path')
                    );

                $item = $page->toArray()['props']['photos'][0];
                $this->assertIsString($item['thumbnailUrl']);
                $this->assertStringContainsString('/storage', $item['thumbnailUrl']);
                $this->assertIsString($item['optimizedUrl']);
                $this->assertStringContainsString('/storage', $item['optimizedUrl']);
            });
    }

    // ---- Property 4 & 5: Pagination partitions the ready set; metadata ----

    // Property 4 & 5: 30 ready photos paginate at 24 with disjoint pages whose
    // union is exactly all 30, and metadata signals the next page.
    // Requirements 4.1, 4.2, 4.3, 4.7
    #[Test]
    public function gallery_paginates_at_24_with_stable_disjoint_pages(): void
    {
        Storage::fake('public');

        $event = $this->activeEvent(['slug' => 'paginated']);

        for ($i = 0; $i < 30; $i++) {
            $this->readyPhoto($event);
        }

        $page1Uuids = [];
        $page2Uuids = [];

        // Page 1: 24 items, current_page 1, last_page 2.
        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$page1Uuids) {
                $page->component('Public/Gallery')
                    ->has('photos', 24)
                    ->where('pagination.current_page', 1)
                    ->where('pagination.last_page', 2);

                $page1Uuids = collect($page->toArray()['props']['photos'])->pluck('uuid')->all();
            });

        // Page 2: remaining 6 items, current_page 2.
        $this->get("/e/{$event->slug}/gallery?page=2")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$page2Uuids) {
                $page->component('Public/Gallery')
                    ->has('photos', 6)
                    ->where('pagination.current_page', 2)
                    ->where('pagination.last_page', 2);

                $page2Uuids = collect($page->toArray()['props']['photos'])->pluck('uuid')->all();
            });

        // Pages are pairwise disjoint.
        $this->assertEmpty(array_intersect($page1Uuids, $page2Uuids));

        // Their union is exactly the event's 30 ready photos.
        $union = array_unique(array_merge($page1Uuids, $page2Uuids));
        $this->assertCount(30, $union);
        $this->assertEqualsCanonicalizing(
            $event->photos()->where('status', 'ready')->pluck('uuid')->all(),
            $union
        );
    }

    // ---- Property 1: empty gallery renders successfully ----

    // Property 1: an active event with zero ready photos still resolves with
    // an empty photos payload. Requirements 1.2, 9.1
    #[Test]
    public function empty_gallery_renders_ok(): void
    {
        $event = $this->activeEvent(['slug' => 'empty-gallery']);

        $this->get("/e/{$event->slug}/gallery")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Gallery')
                ->has('photos', 0)
            );
    }
}
