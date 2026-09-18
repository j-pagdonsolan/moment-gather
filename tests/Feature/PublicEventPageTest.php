<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicEventPageTest extends TestCase
{
    use RefreshDatabase;

    // Property: Feature public-event-page, Property 1 (Visibility soundness) &
    // Property 2 (Slug resolution): an active, non-deleted event is reachable
    // by its slug and the rendered payload carries that event's name.
    #[Test]
    public function active_event_is_accessible_by_slug(): void
    {
        $event = Event::factory()->create([
            'status' => 'active',
            'slug'   => 'john-jane-wedding',
        ]);

        $this->get('/e/john-jane-wedding')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->where('event.name', $event->name)
            );
    }

    // Property: Feature public-event-page, Property 1 (Visibility soundness):
    // a slug with no matching row resolves to HTTP 404.
    #[Test]
    public function nonexistent_slug_returns_404(): void
    {
        $this->get('/e/does-not-exist')->assertNotFound();
    }

    // Property: Feature public-event-page, Property 1 (Visibility soundness):
    // any non-active status (draft case) is never public and returns 404.
    #[Test]
    public function non_active_status_draft_case_returns_404(): void
    {
        Event::factory()->create([
            'status' => 'draft', // hypothetical non-active status
            'slug'   => 'draft-event',
        ]);

        $this->get('/e/draft-event')->assertNotFound();
    }

    // Property: Feature public-event-page, Property 1 (Visibility soundness):
    // archived events are not public and return 404.
    #[Test]
    public function archived_event_returns_404(): void
    {
        Event::factory()->create([
            'status' => 'archived',
            'slug'   => 'archived-event',
        ]);

        $this->get('/e/archived-event')->assertNotFound();
    }

    // Property: Feature public-event-page, Property 1 (Visibility soundness):
    // soft-deleted events are excluded by the SoftDeletes scope and return 404.
    #[Test]
    public function soft_deleted_event_returns_404(): void
    {
        $event = Event::factory()->create([
            'status' => 'active',
            'slug'   => 'deleted-event',
        ]);

        $event->delete(); // soft delete

        $this->get('/e/deleted-event')->assertNotFound();
    }

    // Property: Feature public-event-page, Property 1 (Visibility soundness):
    // the public page is served without authentication.
    #[Test]
    public function public_page_is_reachable_without_authentication(): void
    {
        Event::factory()->create([
            'status' => 'active',
            'slug'   => 'guest-visible',
        ]);

        // No actingAs() — request is made as a guest.
        $this->get('/e/guest-visible')->assertOk();
    }

    // Property: Feature public-event-page, Property 2 (Slug resolution) &
    // Property 5 (Content fidelity): the payload carries the event name and
    // each present optional field with its database value.
    #[Test]
    public function payload_contains_name_and_present_optional_fields(): void
    {
        $event = Event::factory()->create([
            'status'      => 'active',
            'slug'        => 'full-details',
            'description' => 'A lovely celebration.',
            'event_date'  => '2026-09-20',
            'location'    => 'Seattle',
        ]);

        $this->get('/e/full-details')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->where('event.name', $event->name)
                ->where('event.description', 'A lovely celebration.')
                ->where('event.event_date', '2026-09-20')
                ->where('event.location', 'Seattle')
            );
    }

    // Property: Feature public-event-page, Property 4 (Upload-flag fidelity):
    // upload_enabled = true is reflected in the payload (Upload CTA shown).
    #[Test]
    public function upload_cta_shown_when_upload_enabled_true(): void
    {
        Event::factory()->create([
            'status'         => 'active',
            'slug'           => 'uploads-open',
            'upload_enabled' => true,
        ]);

        $this->get('/e/uploads-open')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->where('event.upload_enabled', true)
            );
    }

    // Property: Feature public-event-page, Property 4 (Upload-flag fidelity):
    // upload_enabled = false is reflected in the payload (uploads closed).
    #[Test]
    public function uploads_closed_when_upload_enabled_false(): void
    {
        Event::factory()->create([
            'status'         => 'active',
            'slug'           => 'uploads-closed',
            'upload_enabled' => false,
        ]);

        $this->get('/e/uploads-closed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->where('event.upload_enabled', false)
            );
    }

    // Property: Feature public-event-page, Property 3 (Privacy of payload):
    // organizer-private fields never cross the boundary to the frontend.
    #[Test]
    public function payload_excludes_organizer_private_data(): void
    {
        Event::factory()->create([
            'status' => 'active',
            'slug'   => 'private-check',
        ]);

        $this->get('/e/private-check')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->missing('event.id')
                ->missing('event.uuid')
                ->missing('event.user_id')
                ->missing('event.email')
                ->missing('event.user')
            );
    }

    // Property: Feature photo-gallery, Property 11 (Ready-photo-count correctness):
    // event.photoCount equals the number of the event's ready photos.
    #[Test]
    public function event_page_exposes_ready_photo_count(): void
    {
        $event = Event::factory()->create(['status' => 'active', 'slug' => 'count-check']);

        Photo::factory()->for($event)->count(3)->create(['status' => 'ready']);
        Photo::factory()->for($event)->create(['status' => 'pending']);

        $this->get('/e/count-check')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
                ->where('event.photoCount', 3)
            );
    }
}
