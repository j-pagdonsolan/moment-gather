<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 10: Payment-outcome correctness
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.provider'       => 'fake',
            'billing.webhook_secret' => 'test-webhook-secret',
        ]);
    }

    /**
     * Drive the real checkout endpoint for a user and extract the signed token
     * that the fake-checkout URL carries in its ?token= query parameter.
     */
    private function startCheckoutAndExtractToken(User $user, string $plan = 'pro'): string
    {
        $response = $this->actingAs($user)->post(route('billing.checkout'), ['plan' => $plan]);

        $location = (string) $response->headers->get('Location');

        $query = parse_url($location, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);

        $this->assertArrayHasKey('token', $params, 'Checkout redirect is missing a signed token.');

        return (string) $params['token'];
    }

    /**
     * Complete the fake hosted checkout with the chosen outcome. This is the
     * app's real upgrade path: complete() emits an authoritative signed webhook
     * in-process that grants/denies Pro.
     */
    private function completeCheckout(User $user, string $token, string $outcome): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('billing.fake-checkout.complete'), [
            'token'   => $token,
            'outcome' => $outcome,
        ]);
    }

    // Feature: saas-billing, Property 10: Payment-outcome correctness
    // Validates: Requirements 16.1, 16.2, 16.3, 12.4
    #[Test]
    public function successful_payment_grants_pro_and_records_payment(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        $token    = $this->startCheckoutAndExtractToken($user);
        $response = $this->completeCheckout($user, $token, 'success');

        $response->assertRedirect(route('billing.checkout.success'));

        $user->refresh();
        $this->assertTrue($billing->isPro($user));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status'  => Subscription::STATUS_ACTIVE,
            'plan'    => 'pro',
        ]);

        $this->assertSame(
            1,
            Payment::where('user_id', $user->id)->where('status', Payment::STATUS_SUCCEEDED)->count(),
            'Expected exactly one succeeded Payment for the user.',
        );
    }

    // Feature: saas-billing, Property 10: Payment-outcome correctness
    // Validates: Requirements 16.1, 16.2, 16.3, 12.4
    #[Test]
    public function failed_payment_does_not_grant_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        $token    = $this->startCheckoutAndExtractToken($user);
        $response = $this->completeCheckout($user, $token, 'fail');

        $response->assertRedirect(route('billing.checkout.cancel'));

        $user->refresh();
        $this->assertFalse($billing->isPro($user));

        $this->assertSame(
            0,
            Payment::where('user_id', $user->id)->where('status', Payment::STATUS_SUCCEEDED)->count(),
            'A failed checkout must not create a succeeded Payment.',
        );

        // A failed Payment MAY be recorded; if present it must be marked failed.
        $failedPayment = Payment::where('user_id', $user->id)->first();

        if ($failedPayment !== null) {
            $this->assertSame(Payment::STATUS_FAILED, $failedPayment->status);
        }
    }

    // Feature: saas-billing, Property 10: Payment-outcome correctness
    // Validates: Requirements 16.1, 16.2, 16.3, 12.4
    #[Test]
    public function canceled_checkout_no_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        $token    = $this->startCheckoutAndExtractToken($user);
        $response = $this->completeCheckout($user, $token, 'cancel');

        $response->assertRedirect(route('billing.checkout.cancel'));

        $user->refresh();
        $this->assertFalse($billing->isPro($user));

        // Cancel emits no webhook, so no Payment rows (succeeded or failed) exist.
        $this->assertSame(
            0,
            Payment::where('user_id', $user->id)->count(),
            'A canceled checkout must not create any Payment rows.',
        );
    }

    #[Test]
    public function checkout_requires_auth(): void
    {
        // Billing routes are behind auth+verified; a guest is redirected to login.
        $response = $this->post(route('billing.checkout'), ['plan' => 'pro']);

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function fake_checkout_guarded_to_fake_provider(): void
    {
        $user = User::factory()->create();

        // Build a valid token while the fake provider is active.
        $token = $this->startCheckoutAndExtractToken($user);

        // Switching to a real provider must disable the dev/test-only fake checkout.
        config(['billing.provider' => 'stripe']);

        $response = $this->actingAs($user)->get(route('billing.fake-checkout', ['token' => $token]));

        $response->assertNotFound();
    }

    // Feature: saas-billing, Property 10: Payment-outcome correctness
    // Validates: Requirements 16.1, 16.2, 16.3, 12.4
    #[Test]
    public function property_payment_outcome_correctness(): void
    {
        $billing  = app(BillingService::class);
        $outcomes = ['success', 'fail', 'cancel'];

        for ($iteration = 0; $iteration < 20; $iteration++) {
            $outcome = $outcomes[random_int(0, count($outcomes) - 1)];
            $user    = User::factory()->create();

            $token = $this->startCheckoutAndExtractToken($user);
            $this->completeCheckout($user, $token, $outcome);

            $user->refresh();

            $shouldBePro = $outcome === 'success';

            $this->assertSame(
                $shouldBePro,
                $billing->isPro($user),
                sprintf('isPro mismatch for outcome "%s".', $outcome),
            );

            $hasSucceededPayment = Payment::where('user_id', $user->id)
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->exists();

            $this->assertSame(
                $shouldBePro,
                $hasSucceededPayment,
                sprintf('Succeeded-Payment existence mismatch for outcome "%s".', $outcome),
            );
        }
    }
}
