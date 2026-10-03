<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_redirected_to_login_on_admin_routes(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function organizer_blocked_from_admin_routes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');
        $response->assertStatus(403);

        $response = $this->actingAs($user)->get('/admin/users');
        $response->assertStatus(403);
    }

    #[Test]
    public function super_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertStatus(200);

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertStatus(200);

        $response = $this->actingAs($admin)->get('/admin/events');
        $response->assertStatus(200);
    }

    #[Test]
    public function inactive_super_admin_blocked_from_admin_routes(): void
    {
        $admin = User::factory()->superAdmin()->inactive()->create();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(403);
    }
}
