<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 4: Pro-access correctness
class SubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_subscription_grants_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Subscription::factory()->active()->for($user)->create();

        $this->assertTrue($billing->isPro($user));
        $this->assertSame('pro', $billing->currentPlan($user)->slug);
    }

    #[Test]
    public function canceled_but_in_period_grants_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Subscription::factory()->canceledButActive()->for($user)->create();

        // Canceled with cancel_at_period_end + future period end → grace period.
        $this->assertTrue($billing->isPro($user));
        $this->assertSame('pro', $billing->currentPlan($user)->slug);
    }

    #[Test]
    public function expired_subscription_loses_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Subscription::factory()->expired()->for($user)->create();

        $this->assertFalse($billing->isPro($user));
        $this->assertSame('free', $billing->currentPlan($user)->slug);
    }

    #[Test]
    public function incomplete_subscription_no_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Subscription::factory()->incomplete()->for($user)->create();

        $this->assertFalse($billing->isPro($user));
    }

    #[Test]
    public function past_due_no_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Subscription::factory()->pastDue()->for($user)->create();

        // past_due is not active; isActiveNow() is only true for active or
        // canceled-grace subscriptions.
        $this->assertFalse($billing->isPro($user));
    }

    #[Test]
    public function no_subscription_is_free(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        $this->assertFalse($billing->isPro($user));
        $this->assertSame('free', $billing->currentPlan($user)->slug);
        $this->assertFalse($billing->hasActiveSubscription($user));
    }

    #[Test]
    public function failed_payment_only_no_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();
        Payment::factory()->failed()->for($user)->create();

        // A failed payment with no active subscription grants no access.
        $this->assertFalse($billing->isPro($user));
    }

    // Feature: saas-billing, Property 4: Pro-access correctness
    // Validates: Requirements 6.2, 6.5, 6.6, 11.3, 11.4, 11.5
    #[Test]
    public function property_pro_access_correctness(): void
    {
        $billing = app(BillingService::class);

        // Each state maps to whether it should grant Pro access.
        $states = [
            ['state' => 'active',           'grantsPro' => true],
            ['state' => 'canceledButActive', 'grantsPro' => true],
            ['state' => 'expired',          'grantsPro' => false],
            ['state' => 'incomplete',       'grantsPro' => false],
            ['state' => 'pastDue',          'grantsPro' => false],
            ['state' => 'none',             'grantsPro' => false],
        ];

        for ($iteration = 0; $iteration < 30; $iteration++) {
            $case = $states[random_int(0, count($states) - 1)];
            $user = User::factory()->create();

            if ($case['state'] !== 'none') {
                Subscription::factory()->{$case['state']}()->for($user)->create();
            }

            $this->assertSame(
                $case['grantsPro'],
                $billing->isPro($user),
                sprintf('isPro mismatch for state "%s"', $case['state']),
            );

            $this->assertSame(
                $case['grantsPro'] ? 'pro' : 'free',
                $billing->currentPlan($user)->slug,
                sprintf('currentPlan mismatch for state "%s"', $case['state']),
            );
        }
    }
}
