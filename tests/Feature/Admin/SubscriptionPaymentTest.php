<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_views_all_subscriptions(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $subA = Subscription::factory()->create([
            'user_id' => $userA->id,
            'plan' => 'pro',
            'provider_subscription_id' => 'sub_test_a',
        ]);

        $subB = Subscription::factory()->create([
            'user_id' => $userB->id,
            'plan' => 'free',
            'provider_subscription_id' => 'sub_test_b',
        ]);

        $response = $this->actingAs($admin)->get('/admin/subscriptions');

        $response->assertStatus(200);
        
        // Both subscriptions should be in the response
        $response->assertSee($userA->name);
        $response->assertSee($userB->name);
        
        // provider_subscription_id should NOT be exposed
        $response->assertDontSee('sub_test_a');
        $response->assertDontSee('sub_test_b');
    }

    #[Test]
    public function organizer_only_sees_own_subscriptions(): void
    {
        $organizerA = User::factory()->create();
        $organizerB = User::factory()->create();

        $subA = Subscription::factory()->create([
            'user_id' => $organizerA->id,
            'plan' => 'pro',
        ]);

        $subB = Subscription::factory()->create([
            'user_id' => $organizerB->id,
            'plan' => 'free',
        ]);

        // Organizer A accesses the organizer billing route (not the admin route)
        $response = $this->actingAs($organizerA)->get('/billing');

        $response->assertStatus(200);
        
        // Should only see their own subscription data
        // The organizer billing page should show their subscription
        $response->assertSee($organizerA->name);
        
        // Should NOT see organizer B's subscription
        $response->assertDontSee($organizerB->name);
    }

    #[Test]
    public function admin_views_all_payments(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $paymentA = Payment::factory()->create([
            'user_id' => $userA->id,
            'provider_payment_id' => 'pay_test_a',
            'amount' => 2900,
            'currency' => 'usd',
            'status' => Payment::STATUS_SUCCEEDED,
            'metadata' => ['secret_key' => 'secret_value_a'],
        ]);

        $paymentB = Payment::factory()->create([
            'user_id' => $userB->id,
            'provider_payment_id' => 'pay_test_b',
            'amount' => 1500,
            'currency' => 'eur',
            'status' => Payment::STATUS_SUCCEEDED,
            'metadata' => ['secret_key' => 'secret_value_b'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/payments');

        $response->assertStatus(200);
        
        // Both payments should be in the response (user names)
        $response->assertSee($userA->name);
        $response->assertSee($userB->name);
        
        // provider_payment_id should NOT be exposed
        $response->assertDontSee('pay_test_a');
        $response->assertDontSee('pay_test_b');
        
        // metadata should NOT be exposed
        $response->assertDontSee('secret_key');
        $response->assertDontSee('secret_value_a');
        $response->assertDontSee('secret_value_b');
    }

    #[Test]
    public function admin_cannot_mutate_subscriptions_or_payments(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $subscription = Subscription::factory()->create();
        $payment = Payment::factory()->create();

        // Try to POST to subscriptions endpoint (should not exist)
        $responseSubPost = $this->actingAs($admin)->post('/admin/subscriptions', [
            'plan' => 'pro',
        ]);
        $this->assertContains($responseSubPost->status(), [404, 405]);

        // Try to PUT to subscription (should not exist)
        $responseSubPut = $this->actingAs($admin)->put("/admin/subscriptions/{$subscription->id}", [
            'status' => 'canceled',
        ]);
        $this->assertContains($responseSubPut->status(), [404, 405]);

        // Try to DELETE subscription (should not exist)
        $responseSubDelete = $this->actingAs($admin)->delete("/admin/subscriptions/{$subscription->id}");
        $this->assertContains($responseSubDelete->status(), [404, 405]);

        // Try to POST to payments endpoint (should not exist)
        $responsePayPost = $this->actingAs($admin)->post('/admin/payments', [
            'amount' => 1000,
        ]);
        $this->assertContains($responsePayPost->status(), [404, 405]);

        // Try to PUT to payment (should not exist)
        $responsePayPut = $this->actingAs($admin)->put("/admin/payments/{$payment->id}", [
            'status' => 'refunded',
        ]);
        $this->assertContains($responsePayPut->status(), [404, 405]);

        // Try to DELETE payment (should not exist)
        $responsePayDelete = $this->actingAs($admin)->delete("/admin/payments/{$payment->id}");
        $this->assertContains($responsePayDelete->status(), [404, 405]);
    }
}
