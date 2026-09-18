<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_get_dashboard_redirects_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guestOrganizerRoutes(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'events index'  => ['get', '/events'],
            'events create' => ['get', '/events/create'],
            'events show'   => ['get', "/events/{$uuid}"],
            'events edit'   => ['get', "/events/{$uuid}/edit"],
        ];
    }

    #[Test]
    #[DataProvider('guestOrganizerRoutes')]
    public function guest_get_organizer_routes_redirect_to_login(string $method, string $url): void
    {
        $response = $this->call($method, $url);

        $response->assertRedirect('/login');
    }

    #[Test]
    public function authenticated_user_can_create_an_event(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/events', ['name' => 'My Event']);

        $response->assertRedirect();
    }

    #[Test]
    public function created_event_belongs_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/events', ['name' => 'My Event']);

        $this->assertDatabaseHas('events', [
            'name'    => 'My Event',
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function user_can_own_multiple_events(): void
    {
        $user = User::factory()->create();

        Event::factory()->count(3)->for($user)->create();

        $this->assertSame(3, $user->events()->count());
    }

    #[Test]
    public function authenticated_user_can_update_their_event(): void
    {
        $user  = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $response = $this->actingAs($user)->put("/events/{$event->uuid}", [
            'name'   => 'Updated',
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertSame('Updated', $event->fresh()->name);
    }

    #[Test]
    public function authenticated_user_can_soft_delete_their_event(): void
    {
        $user  = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete("/events/{$event->uuid}");

        $response->assertRedirect('/events');
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    #[Test]
    public function user_cannot_update_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->actingAs($other)->put("/events/{$event->uuid}", [
            'name'   => 'Hacked',
            'status' => 'active',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function user_cannot_delete_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->actingAs($other)->delete("/events/{$event->uuid}");

        $response->assertForbidden();
    }
}
