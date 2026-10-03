<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\Photo;
use App\Models\PlanOverride;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function organizer_idor_blocked(): void
    {
        $organizerA = User::factory()->create();

        // Organizer A tries to access admin events route
        $response = $this->actingAs($organizerA)->get('/admin/events');

        // Should be blocked by EnsureSuperAdmin middleware before any IDOR risk
        $response->assertStatus(403);
    }

    #[Test]
    public function privilege_escalation_blocked(): void
    {
        $organizer = User::factory()->create();
        
        // Create the super_admin role
        $role = Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['display_name' => 'Super Admin']
        );

        // Organizer tries to assign super_admin role to themselves
        $response = $this->actingAs($organizer)->post("/admin/users/{$organizer->id}/roles/assign", [
            'role_id' => $role->id,
        ]);

        // Should be blocked by middleware
        $response->assertStatus(403);
    }

    #[Test]
    public function self_promotion_blocked(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $role = Role::where('name', 'super_admin')->first();

        // Admin tries to assign super_admin role to themselves (already has it)
        $response = $this->actingAs($admin)->post("/admin/users/{$admin->id}/roles/assign", [
            'role_id' => $role->id,
        ]);

        // Should be blocked by self-modification guard in controller
        $response->assertStatus(422);
    }

    #[Test]
    public function inactive_user_blocked_at_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        // Attempt to login with correct credentials but inactive account
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Should not be authenticated (Fortify callback returns null)
        $this->assertGuest();
    }

    #[Test]
    public function inactive_organizer_blocked_on_organizer_routes(): void
    {
        $organizer = User::factory()->create([
            'is_active' => false,
        ]);

        // Simulate forged/existing session (manually authenticate)
        $this->actingAs($organizer);

        // Try to access organizer dashboard
        $response = $this->get('/dashboard');

        // EnsureActiveUser middleware should block
        $response->assertStatus(403);
    }

    #[Test]
    public function secrets_never_in_responses(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create([
            'password' => Hash::make('secret_password'),
            'two_factor_secret' => 'secret_2fa_value',
            'remember_token' => 'secret_remember_token',
        ]);

        // Test user detail endpoint - it's an Inertia response
        $response = $this->actingAs($admin)->get("/admin/users/{$user->id}");

        $response->assertStatus(200);
        
        // Check that secrets don't appear in the HTML response
        $response->assertDontSee('secret_password');
        $response->assertDontSee('secret_2fa_value');
        $response->assertDontSee('secret_remember_token');
        
        // Get the Inertia props
        $props = $response->viewData('page')['props'];
        
        // Ensure user object doesn't contain sensitive fields
        $this->assertArrayNotHasKey('password', $props['user']);
        $this->assertArrayNotHasKey('two_factor_secret', $props['user']);
        $this->assertArrayNotHasKey('remember_token', $props['user']);

        // Test photo detail endpoint
        $event = Event::factory()->create(['user_id' => $user->id]);
        $photo = Photo::factory()->create([
            'event_id' => $event->id,
            'original_path' => 'photos/original/secret_path.jpg',
            'optimized_path' => 'photos/optimized/secret_path.jpg',
            'thumbnail_path' => 'photos/thumbnails/secret_path.jpg',
        ]);

        $response = $this->actingAs($admin)->get("/admin/photos/{$photo->uuid}");

        $response->assertStatus(200);
        
        // Check that paths don't appear in HTML response
        $response->assertDontSee('photos/original/secret_path.jpg');
        $response->assertDontSee('photos/optimized/secret_path.jpg');
        $response->assertDontSee('photos/thumbnails/secret_path.jpg');
        
        // Get the Inertia props
        $props = $response->viewData('page')['props'];
        
        // Ensure photo object doesn't contain storage paths
        $this->assertArrayNotHasKey('original_path', $props['photo']);
        $this->assertArrayNotHasKey('optimized_path', $props['photo']);
        $this->assertArrayNotHasKey('thumbnail_path', $props['photo']);
    }

    #[Test]
    public function plan_override_null_coalesce_correctness(): void
    {
        // Test case 1: All fields null in PlanOverride - should fall back to config
        $overrideAllNull = PlanOverride::create([
            'slug' => 'test-plan-null',
            'max_active_events' => null,
            'max_photos_per_event' => null,
            'max_storage_bytes' => null,
            'price' => null,
            'is_active' => true,
        ]);

        $planFromNull = \App\Billing\Plan::fromEffectiveConfig('test-plan-null');

        // Should fall back to free plan config (default when plan not found)
        $freeConfig = config('plans.free');
        $this->assertEquals($freeConfig['max_active_events'], $planFromNull->maxActiveEvents);
        $this->assertEquals($freeConfig['max_photos_per_event'], $planFromNull->maxPhotosPerEvent);
        $this->assertEquals($freeConfig['max_storage_bytes'], $planFromNull->maxStorageBytes);

        // Test case 2: All fields set in PlanOverride - should use override values
        $overrideAllSet = PlanOverride::create([
            'slug' => 'test-plan-set',
            'max_active_events' => 99,
            'max_photos_per_event' => 999,
            'max_storage_bytes' => 9999999,
            'price' => 9900,
            'is_active' => true,
        ]);

        $planFromSet = \App\Billing\Plan::fromEffectiveConfig('test-plan-set');

        // Should use all override values
        $this->assertEquals(99, $planFromSet->maxActiveEvents);
        $this->assertEquals(999, $planFromSet->maxPhotosPerEvent);
        $this->assertEquals(9999999, $planFromSet->maxStorageBytes);
        $this->assertEquals(9900, $planFromSet->price);

        // Test case 3: Mix of null and set values - should use override when set, config when null
        $overrideMixed = PlanOverride::create([
            'slug' => 'free',
            'max_active_events' => 10, // override
            'max_photos_per_event' => null, // should fall back to config
            'max_storage_bytes' => null, // should fall back to config
            'price' => null, // should fall back to config
            'is_active' => true,
        ]);

        $planFromMixed = \App\Billing\Plan::fromEffectiveConfig('free');

        // Should use override for max_active_events
        $this->assertEquals(10, $planFromMixed->maxActiveEvents);

        // Should use config for the rest
        $this->assertEquals($freeConfig['max_photos_per_event'], $planFromMixed->maxPhotosPerEvent);
        $this->assertEquals($freeConfig['max_storage_bytes'], $planFromMixed->maxStorageBytes);
        $this->assertEquals($freeConfig['price'], $planFromMixed->price);
    }
}
