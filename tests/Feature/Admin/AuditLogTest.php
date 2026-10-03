<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Photo;
use App\Models\PlanOverride;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function audit_log_created_on_user_activate(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->inactive()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/activate");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_activated',
            'user_id' => $admin->id,
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);

        $log = AuditLog::where('action', 'user_activated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertEquals('User', $log->target_type);
        $this->assertEquals($user->id, $log->target_id);
    }

    #[Test]
    public function audit_log_created_on_role_assign(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/roles/assign", [
            'role_id' => $role->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role_assigned',
            'user_id' => $admin->id,
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);

        $log = AuditLog::where('action', 'role_assigned')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
    }

    #[Test]
    public function audit_log_created_on_event_delete(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $organizer->id]);

        $this->actingAs($admin)->delete("/admin/events/{$event->uuid}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_deleted',
            'user_id' => $admin->id,
            'target_type' => 'Event',
        ]);

        $log = AuditLog::where('action', 'event_deleted')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
    }

    #[Test]
    public function audit_log_created_on_photo_delete(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $organizer->id]);
        $photo = Photo::factory()->create(['event_id' => $event->id]);

        $this->actingAs($admin)->delete("/admin/photos/{$photo->uuid}");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'photo_deleted',
            'user_id' => $admin->id,
            'target_type' => 'Photo',
        ]);

        $log = AuditLog::where('action', 'photo_deleted')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
    }

    #[Test]
    public function audit_log_created_on_plan_update(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->put('/admin/plans/free', [
            'max_active_events' => 5,
            'max_photos_per_event' => 50,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'plan_limit_updated',
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('action', 'plan_limit_updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
    }

    #[Test]
    public function organizer_cannot_access_audit_logs(): void
    {
        $organizer = User::factory()->create();

        $response = $this->actingAs($organizer)->get('/admin/audit-logs');

        $response->assertStatus(403);
    }

    #[Test]
    public function no_delete_endpoint_for_audit_logs(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $log = AuditLog::factory()->create();

        $response = $this->actingAs($admin)->delete("/admin/audit-logs/{$log->id}");

        $this->assertContains($response->status(), [404, 405]);
    }

    #[Test]
    public function user_id_set_null_when_admin_deleted(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        // Create an audit log with the admin as the actor
        $log = AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'user_activated',
            'target_type' => 'User',
            'target_id' => $user->id,
            'description' => 'User activated.',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ]);

        $this->assertNotNull($log->user_id);
        $this->assertEquals($admin->id, $log->user_id);

        // Delete the admin user (should trigger ON DELETE SET NULL)
        $admin->delete();

        // Refresh the log from the database
        $log->refresh();

        // user_id should now be null
        $this->assertNull($log->user_id);
    }
}
