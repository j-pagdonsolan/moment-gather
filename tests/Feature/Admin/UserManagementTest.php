<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_list_users(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertStatus(200);
    }

    #[Test]
    public function admin_can_search_users(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['email' => 'testuser@example.com']);

        $response = $this->actingAs($admin)->get('/admin/users?search=testuser');

        $response->assertStatus(200);
        // Check that the response contains the user (Inertia props)
        $props = $response->viewData('page')['props'];
        $this->assertTrue(collect($props['users']['data'])->contains('email', 'testuser@example.com'));
    }

    #[Test]
    public function admin_can_filter_users_by_role(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $organizer = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/users?role=super_admin');

        $response->assertStatus(200);
        // The response should only contain admins
        $props = $response->viewData('page')['props'];
        $users = collect($props['users']['data']);
        
        // All returned users should have super_admin role
        foreach ($users as $user) {
            $this->assertContains('super_admin', $user['roles']);
        }
    }

    #[Test]
    public function admin_can_view_user_detail(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/users/{$user->id}");

        $response->assertStatus(200);
        
        // Assert no password or secrets in response
        $props = $response->viewData('page')['props'];
        $userData = $props['user'];
        
        $this->assertArrayNotHasKey('password', $userData);
        $this->assertArrayNotHasKey('two_factor_secret', $userData);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $userData);
        $this->assertArrayNotHasKey('remember_token', $userData);
    }

    #[Test]
    public function admin_can_activate_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->inactive()->create();

        $this->assertFalse($user->is_active);

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/activate");

        $response->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);
        
        // Assert audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user_activated',
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function admin_can_deactivate_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/deactivate");

        $response->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        
        // Assert audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user_deactivated',
            'target_type' => 'User',
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function cannot_deactivate_last_super_admin(): void
    {
        // Create only one super admin
        $admin = User::factory()->superAdmin()->create();

        $this->assertTrue($admin->is_active);

        $response = $this->actingAs($admin)->post("/admin/users/{$admin->id}/deactivate");

        $response->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }
}
