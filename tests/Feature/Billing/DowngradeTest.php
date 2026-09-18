<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Models\Event;
use App\Models\Photo;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Downgrade behaviour for the SaaS billing foundation.
 *
 * A "downgraded" Pro -> Free user is modelled as a user whose subscription is
 * EXPIRED: {@see BillingService::isPro()} then treats them as Free, yet they
 * may already own MORE events/photos than the Free plan permits. The system
 * must NEVER auto-delete that content, must BLOCK new create/upload while
 * over-limit, and must ALWAYS allow viewing existing content.
 */
class DowngradeTest extends TestCase
{
    use RefreshDatabase;

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    /**
     * A downgraded (expired-subscription) user who already owns more than the
     * Free plan allows, with the requested number of active events each holding
     * one ready photo.
     */
    private function overLimitUser(int $activeEvents = 5): User
    {
        $user = User::factory()->create();
        Subscription::factory()->expired()->for($user)->create();

        Event::factory()
            ->active()
            ->count($activeEvents)
            ->for($user)
            ->create(['upload_enabled' => true])
            ->each(function (Event $event): void {
                Photo::factory()->ready()->for($event)->create();
            });

        return $user;
    }

    private function fixtureFile(string $name = 'sample.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        return new UploadedFile(
            base_path("tests/Fixtures/{$name}"),
            $name,
            $mime,
            null,
            true // test mode
        );
    }

    // Feature: saas-billing, Property 7: Downgrade content preservation
    #[Test]
    public function downgrade_preserves_events_and_photos(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->overLimitUser(5);

        // The downgrade (expired subscription) deletes nothing.
        $this->assertSame(5, Event::where('user_id', $user->id)->count());
        $this->assertSame(5, Photo::query()
            ->join('events', 'events.id', '=', 'photos.event_id')
            ->where('events.user_id', $user->id)
            ->count());

        // BillingService treats the expired-subscription user as Free.
        $this->assertFalse($this->billing()->isPro($user));
    }

    // Feature: saas-billing, Property 9: Downgrade limit enforcement
    #[Test]
    public function downgrade_blocks_new_event_creation(): void
    {
        $user = $this->overLimitUser(5);

        // Creating a new active event is blocked while over the Free limit (1).
        $this->actingAs($user)
            ->from('/events')
            ->post('/events', ['name' => 'X'])
            ->assertRedirect('/events');

        $this->assertSame(5, $this->billing()->activeEventCount($user));
        $this->assertDatabaseMissing('events', [
            'user_id' => $user->id,
            'name'    => 'X',
        ]);

        // Viewing is never blocked: index and an owned event's show page work.
        $this->actingAs($user)->get('/events')->assertOk();

        $owned = Event::where('user_id', $user->id)->firstOrFail();
        $this->actingAs($user)->get("/events/{$owned->uuid}")->assertOk();
    }

    // Feature: saas-billing, Property 9: Downgrade limit enforcement
    #[Test]
    public function downgrade_blocks_new_uploads_when_over_limit(): void
    {
        Storage::fake('public');
        Queue::fake();

        // Tighten the Free per-event photo limit so the plan gate (not the
        // abuse cap) is what rejects the batch.
        config(['plans.free.max_photos_per_event' => 2]);

        $owner = User::factory()->create();
        Subscription::factory()->expired()->for($owner)->create();

        $event = Event::factory()
            ->active()
            ->for($owner)
            ->create(['upload_enabled' => true]);

        // Event already AT the Free per-event limit (2 photos).
        Photo::factory()->count(2)->for($event)->create();

        $this->assertFalse($this->billing()->isPro($owner));

        cache()->flush(); // throttle:uploads

        // One more photo would exceed the plan limit -> 403, nothing stored.
        $this->from("/e/{$event->slug}")
            ->post("/e/{$event->slug}/photos", ['photos' => [$this->fixtureFile()]])
            ->assertForbidden();

        $this->assertSame(2, $event->photos()->count());
        Queue::assertNotPushed(\App\Jobs\ProcessPhoto::class);

        // Existing content stays viewable: gallery and public event page 200.
        $this->get("/e/{$event->slug}/gallery")->assertOk();
        $this->get("/e/{$event->slug}")->assertOk();
    }

    // Feature: saas-billing, Property 7: Downgrade content preservation
    #[Test]
    public function downgrade_allows_viewing_existing(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user  = $this->overLimitUser(3);
        $event = Event::where('user_id', $user->id)->firstOrFail();

        // Owner can still view their own event show page.
        $this->actingAs($user)->get("/events/{$event->uuid}")->assertOk();

        // Public attendee page and gallery for an active event stay viewable.
        $this->get("/e/{$event->slug}")->assertOk();
        $this->get("/e/{$event->slug}/gallery")->assertOk();

        // Nothing was deleted by any of these read-only checks.
        $this->assertSame(3, Event::where('user_id', $user->id)->count());
        $this->assertSame(3, Photo::query()
            ->join('events', 'events.id', '=', 'photos.event_id')
            ->where('events.user_id', $user->id)
            ->count());
    }

    // Feature: saas-billing, Property 7: Downgrade content preservation
    // Feature: saas-billing, Property 9: Downgrade limit enforcement
    #[Test]
    public function downgrade_property_holds_over_random_over_limit_users(): void
    {
        Storage::fake('public');
        Queue::fake();

        $billing = $this->billing();

        for ($i = 0; $i < 20; $i++) {
            $activeEvents = random_int(1, 6); // Free limit is 1, so all are over-limit.
            $user = $this->overLimitUser($activeEvents);

            $eventsBefore = Event::where('user_id', $user->id)->count();
            $photosBefore = Photo::query()
                ->join('events', 'events.id', '=', 'photos.event_id')
                ->where('events.user_id', $user->id)
                ->count();

            // Downgraded: no Pro access.
            $this->assertFalse($billing->isPro($user));

            // Over-limit (active >= 1 >= Free limit 1): cannot create new events.
            $this->assertGreaterThanOrEqual(1, $billing->activeEventCount($user));
            $this->assertFalse($billing->canCreateEvent($user));

            // Read-only checks delete nothing; content counts are unchanged.
            $this->assertSame($eventsBefore, Event::where('user_id', $user->id)->count());
            $this->assertSame($photosBefore, Photo::query()
                ->join('events', 'events.id', '=', 'photos.event_id')
                ->where('events.user_id', $user->id)
                ->count());
        }
    }
}
