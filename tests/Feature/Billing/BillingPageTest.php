<?php

namespace Tests\Feature\Billing;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature test for the authenticated Billing settings page (task 11.11).
 *
 * Asserts the Inertia props delivered by {@see \App\Http\Controllers\Billing\BillingController::show()}
 * for both free and Pro users, that usage figures are backend-computed and
 * authoritative, and — critically (R22.5) — that only the PUBLIC payment key is
 * exposed while the secret key and webhook secret never appear anywhere in the
 * response.
 *
 * _Requirements: 13.2, 13.3, 20.2, 24.1, 22.5_
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic billing config for the test: fake provider, a known
        // public key, and secrets whose values must never leak to the client.
        config([
            'billing.provider'       => 'fake',
            'billing.public_key'     => 'pk_test_123',
            'billing.secret_key'     => 'sk_SECRET_do_not_leak',
            'billing.webhook_secret' => 'whsec_SECRET',
        ]);
    }

    #[Test]
    public function billing_page_renders_with_props_for_free_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/billing')
                ->has('plan')
                ->where('isPro', false)
                ->where('subscription', null)
                ->has('usage.events')
                ->has('usage.photos')
                ->has('usage.storage')
                ->has('payments')
                ->where('currency', config('billing.currency'))
            );
    }

    #[Test]
    public function billing_page_reflects_pro_user(): void
    {
        $user = User::factory()->create();

        Subscription::factory()->for($user)->active()->create();

        $payment = Payment::factory()->for($user)->succeeded()->create();

        $this->actingAs($user)
            ->get('/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/billing')
                ->where('isPro', true)
                ->where('plan.slug', 'pro')
                ->where('subscription.status', 'active')
                ->has('payments', 1)
                ->where('payments.0.reference', $payment->provider_payment_id)
            );
    }

    /**
     * CRITICAL (R22.5): the page exposes only the PUBLIC key. The secret key and
     * webhook secret must appear nowhere in the response body or props.
     */
    #[Test]
    public function billing_page_exposes_public_key_not_secret(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/billing')
                ->where('publicKey', 'pk_test_123')
                ->missing('secretKey')
                ->missing('webhookSecret')
            );

        // The raw response must not contain either secret anywhere (props JSON,
        // serialized page object, or markup).
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('sk_SECRET_do_not_leak', $content);
        $this->assertStringNotContainsString('whsec_SECRET', $content);
    }

    /**
     * Usage figures are computed by the backend from persisted records, not
     * trusted from the client. One active event with N photos of known size
     * must be reflected exactly in the props.
     */
    #[Test]
    public function usage_values_are_backend_computed(): void
    {
        $user = User::factory()->create();

        $event = Event::factory()->for($user)->active()->create();

        $fileSizes = [1_000, 2_500, 4_000];
        foreach ($fileSizes as $size) {
            Photo::factory()->for($event)->create(['file_size' => $size]);
        }

        $expectedPhotoCount = count($fileSizes);
        $expectedStorage = array_sum($fileSizes);

        $this->actingAs($user)
            ->get('/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/billing')
                ->where('usage.events.used', 1)
                ->where('usage.photos.used', $expectedPhotoCount)
                ->where('usage.storage.used', $expectedStorage)
            );
    }

    #[Test]
    public function billing_requires_auth(): void
    {
        $this->get('/billing')->assertRedirect('/login');
    }
}
