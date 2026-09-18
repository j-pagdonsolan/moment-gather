<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingService;
use App\Billing\Providers\FakePaymentProvider;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 5: Webhook idempotency
// Feature: saas-billing, Property 6: Webhook signature integrity
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.provider'       => 'fake',
            'billing.webhook_secret' => 'test-webhook-secret',
        ]);

        $this->fake = app(FakePaymentProvider::class);
    }

    /**
     * Post a raw body to the public, CSRF-excluded webhook route with an optional
     * X-Signature server header. Passing null omits the signature header entirely.
     */
    private function postWebhook(string $raw, ?string $sig): TestResponse
    {
        $server = $sig !== null ? ['HTTP_X_SIGNATURE' => $sig] : [];

        return $this->call('POST', '/billing/webhook', [], [], [], $server, $raw);
    }

    /**
     * Build a valid signed payment_succeeded payload for the given user.
     *
     * @return array{0: string, 1: string} the raw body and its valid signature
     */
    private function paymentSucceededPayload(User $user, string $eventId = 'evt_1'): array
    {
        $raw = $this->fake->makeWebhookPayload('payment_succeeded', [
            'user_id'                  => $user->id,
            'plan'                     => 'pro',
            'provider_subscription_id' => 'fake_sub_1',
            'provider_payment_id'      => 'fake_pay_1',
            'amount'                   => 49900,
            'currency'                 => 'PHP',
            'period_start'             => now()->toIso8601String(),
            'period_end'               => now()->addMonth()->toIso8601String(),
        ], eventId: $eventId);

        return [$raw, $this->fake->signPayload($raw)];
    }

    #[Test]
    public function valid_webhook_is_accepted_and_updates_state(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        [$raw, $sig] = $this->paymentSucceededPayload($user);

        $this->postWebhook($raw, $sig)->assertStatus(200);

        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('pro', $subscription->plan);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'status'  => Payment::STATUS_SUCCEEDED,
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'provider_event_id' => 'evt_1',
        ]);

        $this->assertTrue($billing->isPro($user));
    }

    // Feature: saas-billing, Property 6: Webhook signature integrity
    // Validates: Requirements 17.2, 17.3, 22.1
    #[Test]
    public function invalid_signature_is_rejected_no_state_change(): void
    {
        $user = User::factory()->create();

        [$raw] = $this->paymentSucceededPayload($user);

        // Same body, wrong signature → rejected with no state change.
        $this->postWebhook($raw, 'bad')->assertStatus(400);

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    // Feature: saas-billing, Property 6: Webhook signature integrity
    // Validates: Requirements 17.2, 17.3, 22.1
    #[Test]
    public function missing_signature_rejected(): void
    {
        $user = User::factory()->create();

        [$raw] = $this->paymentSucceededPayload($user);

        $this->postWebhook($raw, null)->assertStatus(400);

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    // Feature: saas-billing, Property 5: Webhook idempotency
    // Validates: Requirements 18.1, 18.2, 18.3, 22.8
    #[Test]
    public function duplicate_event_is_idempotent(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        [$raw, $sig] = $this->paymentSucceededPayload($user, 'evt_1');

        // Deliver the exact same signed event twice.
        $this->postWebhook($raw, $sig)->assertStatus(200);
        $this->postWebhook($raw, $sig)->assertStatus(200);

        // Exactly one effect: no duplicated rows.
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Payment::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, WebhookEvent::query()->where('provider_event_id', 'evt_1')->count());

        $this->assertTrue($billing->isPro($user));
    }

    #[Test]
    public function payment_failed_event_does_not_grant_pro(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        $raw = $this->fake->makeWebhookPayload('payment_failed', [
            'user_id'             => $user->id,
            'provider_payment_id' => 'fp',
            'amount'              => 49900,
            'currency'            => 'PHP',
        ], eventId: 'evt_fail');
        $sig = $this->fake->signPayload($raw);

        $this->postWebhook($raw, $sig)->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'status'  => Payment::STATUS_FAILED,
        ]);

        // No active subscription created → no Pro access.
        $this->assertFalse($billing->isPro($user));
    }

    #[Test]
    public function subscription_canceled_grace(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        Subscription::factory()->active()->for($user)->create([
            'provider_subscription_id' => 'fake_sub_x',
        ]);

        $raw = $this->fake->makeWebhookPayload('subscription_canceled', [
            'user_id'                  => $user->id,
            'provider_subscription_id' => 'fake_sub_x',
            'period_end'               => now()->addMonth()->toIso8601String(),
        ], eventId: 'evt_cancel');
        $sig = $this->fake->signPayload($raw);

        $this->postWebhook($raw, $sig)->assertStatus(200);

        $subscription = Subscription::query()
            ->where('provider_subscription_id', 'fake_sub_x')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);

        // Grace period: still Pro until the paid period ends.
        $this->assertTrue($billing->isPro($user->fresh()));
    }

    #[Test]
    public function subscription_expired_revokes(): void
    {
        $billing = app(BillingService::class);
        $user    = User::factory()->create();

        Subscription::factory()->active()->for($user)->create([
            'provider_subscription_id' => 'fake_sub_y',
        ]);

        $raw = $this->fake->makeWebhookPayload('subscription_expired', [
            'user_id'                  => $user->id,
            'provider_subscription_id' => 'fake_sub_y',
        ], eventId: 'evt_expired');
        $sig = $this->fake->signPayload($raw);

        $this->postWebhook($raw, $sig)->assertStatus(200);

        $subscription = Subscription::query()
            ->where('provider_subscription_id', 'fake_sub_y')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->status);

        $this->assertFalse($billing->isPro($user->fresh()));
    }

    // Feature: saas-billing, Property 5: Webhook idempotency
    // Feature: saas-billing, Property 6: Webhook signature integrity
    // Validates: Requirements 18.1, 18.2, 18.3, 22.8, 17.2, 17.3, 22.1
    #[Test]
    public function property_idempotency_and_signature_integrity(): void
    {
        $user = User::factory()->create();

        // Track the event ids we have successfully applied so we can replay them.
        $appliedEventIds = [];

        // Expected row counts driven purely by the ledger of applied events.
        $expectedWebhookEvents = 0;
        $expectedPayments      = 0;

        for ($iteration = 0; $iteration < 20; $iteration++) {
            $choice = random_int(0, 2);

            if ($choice === 0 || $appliedEventIds === []) {
                // (a) A brand-new, valid, uniquely-identified event → applied once.
                $eventId = 'evt_prop_'.$iteration;

                $raw = $this->fake->makeWebhookPayload('payment_succeeded', [
                    'user_id'                  => $user->id,
                    'plan'                     => 'pro',
                    'provider_subscription_id' => 'fake_sub_prop',
                    'provider_payment_id'      => 'fake_pay_'.$iteration,
                    'amount'                   => 49900,
                    'currency'                 => 'PHP',
                    'period_start'             => now()->toIso8601String(),
                    'period_end'               => now()->addMonth()->toIso8601String(),
                ], eventId: $eventId);
                $sig = $this->fake->signPayload($raw);

                $this->postWebhook($raw, $sig)->assertStatus(200);

                $appliedEventIds[] = $eventId;
                $expectedWebhookEvents++;
                $expectedPayments++;
            } elseif ($choice === 1) {
                // (b) A tampered signature → rejected, no change.
                $raw = $this->fake->makeWebhookPayload('payment_succeeded', [
                    'user_id'             => $user->id,
                    'plan'                => 'pro',
                    'provider_payment_id' => 'tampered_'.$iteration,
                    'amount'              => 49900,
                    'currency'            => 'PHP',
                ], eventId: 'evt_tampered_'.$iteration);

                $this->postWebhook($raw, 'not-a-valid-signature')->assertStatus(400);
                // No expected-count change.
            } else {
                // (c) Replay of a prior valid event id → accepted no-op, no new rows.
                $eventId = $appliedEventIds[random_int(0, count($appliedEventIds) - 1)];

                $raw = $this->fake->makeWebhookPayload('payment_succeeded', [
                    'user_id'                  => $user->id,
                    'plan'                     => 'pro',
                    'provider_subscription_id' => 'fake_sub_prop',
                    'provider_payment_id'      => 'fake_pay_replay',
                    'amount'                   => 49900,
                    'currency'                 => 'PHP',
                    'period_start'             => now()->toIso8601String(),
                    'period_end'               => now()->addMonth()->toIso8601String(),
                ], eventId: $eventId);
                $sig = $this->fake->signPayload($raw);

                $this->postWebhook($raw, $sig)->assertStatus(200);
                // No expected-count change: the event id was already applied.
            }

            $this->assertSame(
                $expectedWebhookEvents,
                WebhookEvent::query()->count(),
                sprintf('webhook_events count mismatch at iteration %d', $iteration),
            );

            $this->assertSame(
                $expectedPayments,
                Payment::query()->count(),
                sprintf('payments count mismatch at iteration %d', $iteration),
            );
        }
    }
}
