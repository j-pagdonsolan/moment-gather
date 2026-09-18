<?php

namespace Tests\Feature\Billing;

use App\Billing\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function free_plan_config_defines_expected_limits_and_price(): void
    {
        $free = config('plans.free');

        $this->assertNotNull($free, 'Free plan must exist in config/plans.php');
        $this->assertSame('free', $free['slug']);
        $this->assertSame(0, (int) $free['price']);
        $this->assertSame(1, (int) $free['max_active_events']);
        $this->assertSame(100, (int) $free['max_photos_per_event']);
        $this->assertSame(500 * 1024 * 1024, (int) $free['max_storage_bytes']);
    }

    #[Test]
    public function pro_plan_config_defines_expected_limits_and_interval(): void
    {
        $pro = config('plans.pro');

        $this->assertNotNull($pro, 'Pro plan must exist in config/plans.php');
        $this->assertSame('pro', $pro['slug']);
        $this->assertSame('monthly', $pro['billing_interval']);
        $this->assertSame(10, (int) $pro['max_active_events']);
        $this->assertSame(5000, (int) $pro['max_photos_per_event']);
        $this->assertSame(10 * 1024 * 1024 * 1024, (int) $pro['max_storage_bytes']);
    }

    #[Test]
    public function from_config_builds_the_free_plan(): void
    {
        $plan = Plan::fromConfig('free');

        $this->assertSame('free', $plan->slug);
        $this->assertSame(0, $plan->price);
        $this->assertNull($plan->billingInterval);
        $this->assertSame(1, $plan->maxActiveEvents);
        $this->assertSame(100, $plan->maxPhotosPerEvent);
        $this->assertSame(500 * 1024 * 1024, $plan->maxStorageBytes);
    }

    #[Test]
    public function from_config_builds_the_pro_plan(): void
    {
        $plan = Plan::fromConfig('pro');

        $this->assertSame('pro', $plan->slug);
        $this->assertSame('monthly', $plan->billingInterval);
        $this->assertSame(10, $plan->maxActiveEvents);
        $this->assertSame(5000, $plan->maxPhotosPerEvent);
        $this->assertSame(10 * 1024 * 1024 * 1024, $plan->maxStorageBytes);
    }

    #[Test]
    public function from_config_falls_back_to_free_for_an_undefined_slug(): void
    {
        $plan = Plan::fromConfig('bogus');

        $this->assertSame('free', $plan->slug);
        $this->assertSame(1, $plan->maxActiveEvents);
        $this->assertSame(100, $plan->maxPhotosPerEvent);
        $this->assertSame(500 * 1024 * 1024, $plan->maxStorageBytes);
    }

    #[Test]
    public function plan_limits_are_configurable_via_config_override(): void
    {
        config(['plans.free.max_active_events' => 3]);

        $this->assertSame(3, Plan::fromConfig('free')->maxActiveEvents);
    }
}
