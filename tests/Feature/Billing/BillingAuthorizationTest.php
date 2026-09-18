<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature: saas-billing, Property 8: Authorization / IDOR safety
//
// For any two distinct users A and B, all billing/subscription/payment queries
// executed for A are scoped to A's user_id; A can never read or mutate B's
// billing resources, and all billing routes require authentication.
// Validates: Requirements 21.1, 21.2, 21.3
class BillingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_access_billing(): void
    {
        // GET /billing without auth redirects to login.
        $this->get('/billing')->assertRedirect('/login');

        // Mutating billing endpoints also reject guests (redirect to login).
        $this->post('/billing/checkout')->assertRedirect('/login');
        $this->post('/billing/cancel')->assertRedirect('/login');
    }

    #[Test]
    public function billing_page_shows_only_own_data(): void
    {
        $userA = User::factory()->create();
        Subscription::factory()->active()->for($userA)->create(['plan' => 'pro']);
        Payment::factory()->succeeded()->for($userA)->create([
            'provider_payment_id' => 'A_pay',
        ]);

        $userB = User::factory()->create();
        Subscription::factory()->active()->for($userB)->create(['plan' => 'pro']);
        Payment::factory()->succeeded()->for($userB)->create([
            'provider_payment_id' => 'B_pay',
        ]);

        $response = $this->actingAs($userA)->get('/billing');

        $response->assertOk();

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('settings/billing')
                ->has('payments')
                ->where('subscription.status', Subscription::STATUS_ACTIVE)
                ->where('isPro', true);

            $references = collect($page->toArray()['props']['payments'])
                ->pluck('reference')
                ->all();

            $this->assertContains('A_pay', $references);
            $this->assertNotContains('B_pay', $references);
        });
    }

    #[Test]
    public function cancel_only_affects_own_subscription(): void
    {
        $userA = User::factory()->create();
        $subA = Subscription::factory()->active()->for($userA)->create();

        $userB = User::factory()->create();
        $subB = Subscription::factory()->active()->for($userB)->create();

        $this->actingAs($userA)->post('/billing/cancel');

        $subA->refresh();
        $subB->refresh();

        // A's subscription is canceled at period end.
        $this->assertSame(Subscription::STATUS_CANCELED, $subA->status);
        $this->assertTrue($subA->cancel_at_period_end);

        // B's subscription is completely unchanged.
        $this->assertSame(Subscription::STATUS_ACTIVE, $subB->status);
        $this->assertFalse($subB->cancel_at_period_end);
    }

    // Property 8 bounded loop: for many fresh (A, B) pairs with random billing
    // data, the billing page for A exposes only A's payment references and none
    // of B's. Confirms owner-scoping / no cross-user leakage across inputs.
    #[Test]
    public function billing_payments_prop_never_leaks_other_users_data(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $userA = User::factory()->create();
            $userB = User::factory()->create();

            // Random amount of billing data per user.
            $aCount = random_int(0, 4);
            $bCount = random_int(0, 4);

            if (random_int(0, 1) === 1) {
                Subscription::factory()->active()->for($userA)->create();
            }
            if (random_int(0, 1) === 1) {
                Subscription::factory()->active()->for($userB)->create();
            }

            Payment::factory()->count($aCount)->succeeded()->for($userA)->create();
            Payment::factory()->count($bCount)->succeeded()->for($userB)->create();

            $aReferences = $userA->payments()->pluck('provider_payment_id')->all();
            $bReferences = $userB->payments()->pluck('provider_payment_id')->all();

            $response = $this->actingAs($userA)->get('/billing');

            $response->assertOk();

            $response->assertInertia(function (AssertableInertia $page) use ($aReferences, $bReferences) {
                $page->component('settings/billing')->has('payments');

                $propReferences = collect($page->toArray()['props']['payments'])
                    ->pluck('reference')
                    ->all();

                // Every returned reference belongs to A; none belong to B.
                foreach ($propReferences as $reference) {
                    $this->assertContains($reference, $aReferences, 'Prop leaked a reference not owned by user A');
                    $this->assertNotContains($reference, $bReferences, 'Prop leaked user B payment reference');
                }
            });
        }
    }
}
