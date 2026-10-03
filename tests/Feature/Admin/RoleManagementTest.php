<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_assign_role(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        
        // Get the organizer role
        $organizerRole = Role::firstOrCreate(
            ['name' => 'organizer'],
            ['display_name' => 'Organizer']
        );
        
        $this->assertFalse($user->hasRole('organizer'));

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/roles/assign", [
            'role_id' => $organizerRole->id,
        ]);

        $response->assertRedirect();
        
        // Assert user now has the role
        $this->assertTrue($user->fresh()->hasRole('organizer'));
        
        // Assert audit log entry created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'role_assigned',
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function admin_can_remove_role(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        
        // Assign organizer role first
        $organizerRole = Role::firstOrCreate(
            ['name' => 'organizer'],
            ['display_name' => 'Organizer']
        );
        $user->roles()->attach($organizerRole->id);
        
        $this->assertTrue($user->hasRole('organizer'));

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/roles/remove", [
            'role_id' => $organizerRole->id,
        ]);

        $response->assertRedirect();
        
        // Assert user no longer has the role
        $this->assertFalse($user->fresh()->hasRole('organizer'));
        
        // Assert audit log entry created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'role_removed',
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function self_promotion_rejected(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $superAdminRole = Role::where('name', 'super_admin')->first();
        
        $response = $this->actingAs($admin)->post("/admin/users/{$admin->id}/roles/assign", [
            'role_id' => $superAdminRole->id,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function cannot_remove_last_super_admin_role(): void
    {
        // Create only one super admin
        $admin = User::factory()->superAdmin()->create();
        $superAdminRole = Role::where('name', 'super_admin')->first();
        
        $this->assertTrue($admin->hasRole('super_admin'));

        $response = $this->actingAs($admin)->post("/admin/users/{$admin->id}/roles/remove", [
            'role_id' => $superAdminRole->id,
        ]);

        $response->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    #[Test]
    public function organizer_cannot_access_role_endpoints(): void
    {
        $organizer = User::factory()->create();
        $user = User::factory()->create();
        $organizerRole = Role::firstOrCreate(
            ['name' => 'organizer'],
            ['display_name' => 'Organizer']
        );

        $response = $this->actingAs($organizer)->post("/admin/users/{$user->id}/roles/assign", [
            'role_id' => $organizerRole->id,
        ]);

        $response->assertStatus(403);
    }
}
