<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    #[Test]
    public function dashboard_returns_inertia_page_with_correct_stats_and_recent_events(): void
    {
        $user = User::factory()->create();

        Event::factory()->for($user)->create(['status' => 'active']);
        Event::factory()->for($user)->create(['status' => 'active']);
        Event::factory()->for($user)->create(['status' => 'archived']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.totalEvents', 3)
            ->where('stats.activeEvents', 2)
            ->where('stats.archivedEvents', 1)
            ->has('recentEvents', 3)
        );
    }

    #[Test]
    public function dashboard_stats_exclude_soft_deleted_events(): void
    {
        $user = User::factory()->create();

        Event::factory()->for($user)->create(['status' => 'active']);
        Event::factory()->for($user)->create(['status' => 'active']);
        $deleted = Event::factory()->for($user)->create(['status' => 'archived']);

        $deleted->delete();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.totalEvents', 2)
            ->where('stats.activeEvents', 2)
            ->where('stats.archivedEvents', 0)
            ->has('recentEvents', 2)
        );
    }

    #[Test]
    public function dashboard_stats_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Event::factory()->for($user)->create(['status' => 'active']);
        Event::factory()->for($user)->create(['status' => 'archived']);

        // Events belonging to a different organizer must not be counted.
        Event::factory()->for($otherUser)->create(['status' => 'active']);
        Event::factory()->for($otherUser)->create(['status' => 'active']);
        Event::factory()->for($otherUser)->create(['status' => 'archived']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats.totalEvents', 2)
            ->where('stats.activeEvents', 1)
            ->where('stats.archivedEvents', 1)
            ->has('recentEvents', 2)
        );
    }
}
