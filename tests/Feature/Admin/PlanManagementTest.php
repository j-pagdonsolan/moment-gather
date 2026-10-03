<?php

namespace Tests\Feature\Admin;

use App\Billing\Plan;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\PlanOverride;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_admin_can_view_effective_plans(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin views plans
        $response = $this->actingAs($admin)->get('/admin/plans');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Plans/Index')
            ->has('plans', 2) // free and pro plans
        );
    }

    #[Test]
    public function test_plan_override_applied_correctly(): void
    {
        // Create PlanOverride with custom limit for free plan
        PlanOverride::create([
            'slug' => 'free',
            'max_active_events' => 99,
            'is_active' => true,
        ]);

        // Call Plan::fromEffectiveConfig
        $plan = Plan::fromEffectiveConfig('free');

        // Assert maxActiveEvents is from override
        $this->assertSame(99, $plan->maxActiveEvents);
    }

    #[Test]
    public function test_admin_can_update_plan_limits(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin updates free plan limits
        $response = $this->actingAs($admin)->put('/admin/plans/free', [
            'max_active_events' => 50,
        ]);

        $response->assertRedirect();

        // Verify PlanOverride row created/updated
        $this->assertDatabaseHas('plan_overrides', [
            'slug' => 'free',
            'max_active_events' => 50,
        ]);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'plan_limit_updated',
        ]);
    }

    #[Test]
    public function test_admin_can_deactivate_plan(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin deactivates free plan
        $response = $this->actingAs($admin)->post('/admin/plans/free/deactivate');

        $response->assertRedirect();

        // Verify PlanOverride is_active set to false
        $this->assertDatabaseHas('plan_overrides', [
            'slug' => 'free',
            'is_active' => false,
        ]);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'plan_deactivated',
        ]);
    }

    #[Test]
    public function test_plan_changes_dont_delete_user_content(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);

        // Create user with 5 events on free plan
        $user = User::factory()->create();
        Event::factory()->count(5)->for($user, 'user')->create();

        $this->assertSame(5, $user->events()->count());

        // Create admin
        $admin = User::factory()->create();
        $role = Role::where('name', 'super_admin')->first();
        $admin->roles()->attach($role->id);

        // Admin changes free plan limits
        $this->actingAs($admin)->put('/admin/plans/free', [
            'max_active_events' => 1, // Reduce limit to 1
        ]);

        // Verify user still has 5 events (no cascade delete)
        $this->assertSame(5, $user->events()->count());
    }

    #[Test]
    public function test_organizer_cannot_access_plan_routes(): void
    {
        // Seed roles
        Role::firstOrCreate(['name' => 'organizer'], ['display_name' => 'Organizer']);

        // Create organizer
        $organizer = User::factory()->create();

        // Organizer tries to access plan routes
        $response = $this->actingAs($organizer)->get('/admin/plans');

        $response->assertStatus(403);
    }
}
