<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Event create/update validation + owner happy-path (Requirement 4, R3.3).
 *
 * Validation-rejection convention:
 *   The organizer routes (POST /events, PUT /events/{uuid}) are web/Inertia
 *   routes, not JSON API endpoints. Laravel form-request validation failures on
 *   web routes produce an HTTP 302 redirect back to the originating page with the
 *   errors flashed to the session — NOT a 422. The requirement's "422" therefore
 *   means "validation-rejected"; this file asserts the way the app actually
 *   rejects: `->from(<url>)` then `assertSessionHasErrors('field')` (HTTP 302),
 *   matching the pattern used by GuestPhotoUploadTest. (A `postJson` call would
 *   yield 422, but the app is web/Inertia, so the web-form pattern is correct.)
 *
 * Anti-duplication: EventTest covers CRUD/ownership, EventAccessSecurityTest
 * covers 403 boundaries, SlugGeneratorTest covers slug uniqueness/increment, and
 * EventQrCodeTest covers the owner-200 + publicUrl/QR props. This file only fills
 * the validation gaps and adds one minimal owner happy-path assertion.
 */
class EventValidationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // R4.1 — name is required on create
    // -----------------------------------------------------------------
    #[Test]
    public function create_without_name_is_rejected_with_name_error(): void
    {
        $organizer = User::factory()->create();

        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', ['name' => ''])
            ->assertRedirect('/events/create')
            ->assertSessionHasErrors('name');
    }

    // -----------------------------------------------------------------
    // R4.2 — name > 255 chars rejected on create AND on update
    // -----------------------------------------------------------------
    #[Test]
    public function create_with_name_over_255_chars_is_rejected(): void
    {
        $organizer = User::factory()->create();

        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function update_with_name_over_255_chars_is_rejected(): void
    {
        $organizer = User::factory()->create();
        $event     = Event::factory()->for($organizer)->create();

        $this->actingAs($organizer)
            ->from("/events/{$event->uuid}/edit")
            ->put("/events/{$event->uuid}", [
                'name'   => str_repeat('a', 256),
                'status' => 'active',
            ])
            ->assertSessionHasErrors('name');
    }

    // -----------------------------------------------------------------
    // R4.3 — description > 5000 chars rejected on create
    // -----------------------------------------------------------------
    #[Test]
    public function create_with_description_over_5000_chars_is_rejected(): void
    {
        $organizer = User::factory()->create();

        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', [
                'name'        => 'Valid Name',
                'description' => str_repeat('a', 5001),
            ])
            ->assertSessionHasErrors('description');
    }

    // -----------------------------------------------------------------
    // R4.4 — invalid event_date rejected on create
    // -----------------------------------------------------------------
    #[Test]
    public function create_with_invalid_event_date_is_rejected(): void
    {
        $organizer = User::factory()->create();

        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', [
                'name'       => 'Valid Name',
                'event_date' => 'not-a-date',
            ])
            ->assertSessionHasErrors('event_date');
    }

    // -----------------------------------------------------------------
    // R4.5 — update status must be in {active, archived}
    // -----------------------------------------------------------------
    #[Test]
    public function update_with_draft_status_is_rejected(): void
    {
        $organizer = User::factory()->create();
        $event     = Event::factory()->for($organizer)->create();

        $this->actingAs($organizer)
            ->from("/events/{$event->uuid}/edit")
            ->put("/events/{$event->uuid}", [
                'name'   => 'Valid Name',
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function update_with_bogus_status_is_rejected(): void
    {
        $organizer = User::factory()->create();
        $event     = Event::factory()->for($organizer)->create();

        $this->actingAs($organizer)
            ->from("/events/{$event->uuid}/edit")
            ->put("/events/{$event->uuid}", [
                'name'   => 'Valid Name',
                'status' => 'bogus',
            ])
            ->assertSessionHasErrors('status');
    }

    // -----------------------------------------------------------------
    // R4.6 — status + upload_enabled are server-forced on create
    // -----------------------------------------------------------------
    #[Test]
    public function create_forces_active_status_and_upload_enabled_regardless_of_input(): void
    {
        $organizer = User::factory()->create();

        // Client attempts to set status=archived and upload_enabled=false;
        // the controller must override both. Note these fields are not even in
        // StoreEventRequest rules, so they are stripped and then forced.
        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', [
                'name'           => 'Forced Fields Event',
                'status'         => 'archived',
                'upload_enabled' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $event = Event::where('name', 'Forced Fields Event')->firstOrFail();

        $this->assertSame('active', $event->status);
        $this->assertTrue((bool) $event->upload_enabled);
    }

    // -----------------------------------------------------------------
    // R4.7 — a valid name persists a slugified slug
    // (uniqueness/increment is SlugGeneratorTest's job — not duplicated here)
    // -----------------------------------------------------------------
    #[Test]
    public function create_with_valid_name_persists_a_slugified_slug(): void
    {
        $organizer = User::factory()->create();

        $this->actingAs($organizer)
            ->from('/events/create')
            ->post('/events', ['name' => 'My Cool Event!'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $event = Event::where('name', 'My Cool Event!')->firstOrFail();

        $this->assertNotEmpty($event->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $event->slug);
    }

    // -----------------------------------------------------------------
    // R3.3 — owner GET /events/{uuid} of an owned event → 200 Events/Show
    // (minimal; QR/publicUrl props are asserted by EventQrCodeTest)
    // -----------------------------------------------------------------
    #[Test]
    public function owner_can_view_owned_event_show_page(): void
    {
        $organizer = User::factory()->create();
        $event     = Event::factory()->for($organizer)->create();

        $this->actingAs($organizer)
            ->get("/events/{$event->uuid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Events/Show'));
    }
}
