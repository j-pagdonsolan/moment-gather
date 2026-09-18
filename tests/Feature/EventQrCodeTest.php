<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventQrCodeTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // 5.1 group — organizer payload, authorization, and status-independence
    // ---------------------------------------------------------------------

    // Property: Feature event-qr-code, Property 1 (Public URL correctness) &
    // Property 3 (Authorization and payload): the owner's organizer page
    // renders Events/Show with a publicUrl prop equal to the named public route.
    #[Test]
    public function owner_receives_public_url_prop_on_show(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => 'active']);

        $this->actingAs($owner)
            ->get("/events/{$event->uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Events/Show')
                ->where('publicUrl', route('public.events.show', ['slug' => $event->slug]))
            );
    }

    // Property: Feature event-qr-code, Property 1 (Public URL correctness):
    // the publicUrl carries the event's slug and the /e/ path segment.
    #[Test]
    public function public_url_contains_slug_and_e_path(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => 'active']);

        $this->actingAs($owner)
            ->get("/events/{$event->uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Events/Show')
                ->where('publicUrl', fn ($value) => is_string($value)
                    && str_contains($value, $event->slug)
                    && str_contains($value, '/e/')
                )
            );
    }

    // Property: Feature event-qr-code, Property 3 (Authorization and payload):
    // the existing event data is still delivered alongside the new publicUrl.
    #[Test]
    public function show_payload_still_includes_event_data(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => 'active']);

        $this->actingAs($owner)
            ->get("/events/{$event->uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Events/Show')
                ->has('event')
                ->has('publicUrl')
            );
    }

    // Property: Feature event-qr-code, Property 3 (Authorization and payload):
    // an authenticated non-owner is denied with HTTP 403.
    #[Test]
    public function non_owner_cannot_view_event_show(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => 'active']);

        $this->actingAs($other)
            ->get("/events/{$event->uuid}")
            ->assertForbidden();
    }

    // Property: Feature event-qr-code, Property 3 (Authorization and payload):
    // an unauthenticated visitor is redirected to /login by the auth middleware.
    #[Test]
    public function guest_redirected_from_event_show(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['status' => 'active']);

        $this->get("/events/{$event->uuid}")
            ->assertRedirect('/login');
    }

    // Property: Feature event-qr-code, Property 4 (Owner payload is
    // status-independent): the owner receives the publicUrl prop for owned
    // events regardless of their status (archived, draft).
    #[Test]
    public function owner_receives_public_url_prop_regardless_of_status(): void
    {
        $owner = User::factory()->create();

        foreach (['archived', 'draft'] as $status) {
            $event = Event::factory()->for($owner)->create(['status' => $status]);

            $this->actingAs($owner)
                ->get("/events/{$event->uuid}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Events/Show')
                    ->has('publicUrl')
                );
        }
    }

    // ---------------------------------------------------------------------
    // 5.2 group — public destination visibility parity
    // ---------------------------------------------------------------------

    // Property: Feature event-qr-code, Property 7 (Public destination
    // visibility parity): an active, non-deleted event's publicUrl resolves
    // to HTTP 200 rendering Public/Event.
    #[Test]
    public function public_url_resolves_for_active_event_returns_200(): void
    {
        $event = Event::factory()->create(['status' => 'active']);

        $this->get("/e/{$event->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Event')
            );
    }

    // Property: Feature event-qr-code, Property 7 (Public destination
    // visibility parity): an archived event's publicUrl returns HTTP 404.
    #[Test]
    public function public_url_for_archived_event_returns_404(): void
    {
        $event = Event::factory()->create(['status' => 'archived']);

        $this->get("/e/{$event->slug}")->assertNotFound();
    }

    // Property: Feature event-qr-code, Property 7 (Public destination
    // visibility parity): a draft event's publicUrl returns HTTP 404.
    #[Test]
    public function public_url_for_draft_event_returns_404(): void
    {
        $event = Event::factory()->create(['status' => 'draft']);

        $this->get("/e/{$event->slug}")->assertNotFound();
    }

    // Property: Feature event-qr-code, Property 7 (Public destination
    // visibility parity): a soft-deleted event's publicUrl returns HTTP 404.
    #[Test]
    public function public_url_for_soft_deleted_event_returns_404(): void
    {
        $event = Event::factory()->create(['status' => 'active']);

        $event->delete(); // soft delete

        $this->get("/e/{$event->slug}")->assertNotFound();
    }
}
