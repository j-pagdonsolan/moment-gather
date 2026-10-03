<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_admin_can_list_all_events(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'organizer'], ['display_name' => 'Organizer']);

        // Create 2 different users
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Create events for each user
        $eventA = Event::factory()->for($userA, 'user')->create(['name' => 'Event A']);
        $eventB = Event::factory()->for($userB, 'user')->create(['name' => 'Event B']);

        // Create admin user
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin requests events list
        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Events/Index')
            ->has('events.data', 2)
        );
    }

    #[Test]
    public function test_admin_can_view_event_detail(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create event
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'user')->create();

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin views event detail
        $response = $this->actingAs($admin)->get("/admin/events/{$event->uuid}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Events/Show')
            ->where('event.uuid', $event->uuid)
        );
    }

    #[Test]
    public function test_admin_can_archive_event(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create event with active status
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'user')->create(['status' => 'active']);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin archives event
        $response = $this->actingAs($admin)->post("/admin/events/{$event->uuid}/archive");

        $response->assertRedirect();
        
        // Verify event status is archived
        $this->assertSame('archived', $event->fresh()->status);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'event_archived',
            'target_type' => 'Event',
            'target_id' => $event->id,
        ]);
    }

    #[Test]
    public function test_admin_can_delete_event(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create event
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'user')->create();

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin deletes event
        $response = $this->actingAs($admin)->delete("/admin/events/{$event->uuid}");

        $response->assertRedirect();

        // Verify event is soft-deleted
        $this->assertSoftDeleted('events', ['id' => $event->id]);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'event_deleted',
            'target_type' => 'Event',
            'target_id' => $event->id,
        ]);
    }

    #[Test]
    public function test_organizer_isolation_preserved(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'organizer'], ['display_name' => 'Organizer']);

        // Create organizer A and their event
        $organizerA = User::factory()->create();
        $eventA = Event::factory()->for($organizerA, 'user')->create();

        // Create organizer B (trying to access A's event)
        $organizerB = User::factory()->create();

        // Organizer B tries to access organizer A's event via organizer route (not admin)
        $response = $this->actingAs($organizerB)->get("/events/{$eventA->uuid}");

        $response->assertStatus(403);
    }
}
