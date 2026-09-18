<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Models\Event;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Count of active (status='active', non-soft-deleted) events for a user.
     */
    private function activeEventCount(User $user): int
    {
        return Event::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();
    }

    #[Test]
    public function free_user_can_create_first_event(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/events', ['name' => 'E1']);

        $event = Event::where('user_id', $user->id)->first();

        $this->assertNotNull($event);
        $response->assertRedirect(route('events.show', $event));
        $this->assertSame(1, $this->activeEventCount($user));
        $this->assertSame('active', $event->status);
    }

    #[Test]
    public function free_user_cannot_create_second_active_event(): void
    {
        $user = User::factory()->create();
        Event::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->post('/events', ['name' => 'E2']);

        // Blocked: request bounces back and nothing new was created.
        $response->assertRedirect();
        $this->assertSame(1, $this->activeEventCount($user));
        $this->assertDatabaseMissing('events', [
            'user_id' => $user->id,
            'name'    => 'E2',
        ]);
    }

    #[Test]
    public function archived_events_do_not_count_toward_limit(): void
    {
        $user = User::factory()->create();
        Event::factory()->archived()->for($user)->create();

        // 0 active events, so a Free user may create one.
        $this->assertSame(0, $this->activeEventCount($user));

        $this->actingAs($user)->post('/events', ['name' => 'E']);

        $this->assertSame(1, $this->activeEventCount($user));
    }

    #[Test]
    public function soft_deleted_events_do_not_count(): void
    {
        $user  = User::factory()->create();
        $event = Event::factory()->active()->for($user)->create();
        $event->delete();

        // The soft-deleted event is excluded, so the Free user is under the limit.
        $this->assertSame(0, $this->activeEventCount($user));

        $this->actingAs($user)->post('/events', ['name' => 'E']);

        $this->assertSame(1, $this->activeEventCount($user));
    }

    #[Test]
    public function pro_user_can_create_up_to_ten(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->for($user)->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($user)->post('/events', ['name' => "E{$i}"]);
        }

        $this->assertSame(10, $this->activeEventCount($user));

        // The 11th is blocked.
        $this->actingAs($user)->post('/events', ['name' => 'E11']);

        $this->assertSame(10, $this->activeEventCount($user));
    }

    // Feature: saas-billing, Property 1: Event-limit soundness
    // Validates: Requirements 7.1, 7.2, 7.4, 7.5
    #[Test]
    public function property_event_limit_soundness(): void
    {
        $billing = app(BillingService::class);

        for ($iteration = 0; $iteration < 40; $iteration++) {
            $pro      = (bool) random_int(0, 1);
            $planLimit = $pro ? 10 : 1;
            // Range across the boundary: 0 .. planLimit + 1.
            $active = random_int(0, $planLimit + 1);

            $user = User::factory()->create();

            if ($pro) {
                Subscription::factory()->active()->for($user)->create();
            }

            if ($active > 0) {
                Event::factory()->active()->count($active)->for($user)->create();
            }

            $this->assertSame(
                $active < $planLimit,
                $billing->canCreateEvent($user),
                sprintf(
                    'canCreateEvent mismatch (pro=%s, active=%d, limit=%d)',
                    $pro ? 'true' : 'false',
                    $active,
                    $planLimit,
                ),
            );
        }
    }
}
