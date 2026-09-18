<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\Providers\FakePaymentProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FakeCheckoutController — DEV/TEST-ONLY hosted-checkout backend glue.
 *
 * This surface simulates a real provider's hosted checkout so the entire billing
 * flow can be exercised end-to-end without any external credentials. It is enabled
 * ONLY when config('billing.provider') === 'fake'; every action aborts with 404
 * otherwise, so it can never activate against a real provider.
 *
 * The React page (resources/js/pages/Billing/FakeCheckout.tsx) shows Success / Fail /
 * Cancel buttons that POST the signed checkout token back to complete(), which then
 * emits a signed webhook to our own WebhookController — the authoritative path that
 * actually grants/denies Pro. Using a deterministic event id keeps re-submission of
 * the same checkout idempotent (Property 5).
 */
final class FakeCheckoutController extends Controller
{
    /**
     * Render the fake hosted-checkout page for a signed checkout token.
     *
     * Guards: fake provider only (404), and a valid signed token (403). The decoded
     * token carries user_id/plan/nonce; only the plan is surfaced to the view.
     */
    public function show(Request $request, FakePaymentProvider $fake): Response
    {
        abort_unless(config('billing.provider') === 'fake', 404);

        $token = (string) $request->query('token', '');

        $payload = $fake->verifyCheckoutToken($token);

        if ($payload === null) {
            abort(403);
        }

        return Inertia::render('Billing/FakeCheckout', [
            'token' => $token,
            'plan'  => $payload['plan'] ?? 'pro',
        ]);
    }

    /**
     * Apply a chosen checkout outcome by emitting the corresponding signed webhook
     * to our own WebhookController, then redirect to the matching return route.
     *
     * The webhook path is authoritative: success grants Pro, failure/cancel do not.
     * A fixed event id ('fake_evt_'.nonce) makes re-submitting the same checkout a
     * no-op at the webhook layer (idempotency, Property 5).
     */
    public function complete(Request $request, FakePaymentProvider $fake): RedirectResponse
    {
        abort_unless(config('billing.provider') === 'fake', 404);

        $validated = $request->validate([
            'token'   => ['required', 'string'],
            'outcome' => ['required', 'string', 'in:success,fail,cancel'],
        ]);

        $payload = $fake->verifyCheckoutToken($validated['token']);

        if ($payload === null) {
            abort(403);
        }

        $outcome = $validated['outcome'];

        // Cancel: no webhook, no Pro — just return to the neutral cancel page.
        if ($outcome === 'cancel') {
            return redirect()->route('billing.checkout.cancel');
        }

        $nonce = (string) ($payload['nonce'] ?? '');

        if ($outcome === 'fail') {
            $this->emitWebhook($fake, 'payment_failed', $payload, $nonce);

            return redirect()->route('billing.checkout.cancel');
        }

        // Success: emit the authoritative payment_succeeded webhook, then return.
        $this->emitWebhook($fake, 'payment_succeeded', $payload, $nonce);

        return redirect()->route('billing.checkout.success');
    }

    /**
     * Build a signed webhook payload for the given event type and dispatch it to the
     * authoritative WebhookController in-process (no real HTTP round-trip, no CSRF /
     * middleware re-entry). State changes flow through the same idempotent, signature-
     * verified path a real provider would use.
     *
     * @param  array<string,mixed>  $payload  decoded checkout token (user_id, plan, nonce)
     */
    private function emitWebhook(FakePaymentProvider $fake, string $type, array $payload, string $nonce): void
    {
        $rawPayload = $fake->makeWebhookPayload($type, [
            'user_id'                  => $payload['user_id'] ?? null,
            'plan'                     => $payload['plan'] ?? 'pro',
            'provider_subscription_id' => 'fake_sub_'.$nonce,
            'provider_payment_id'      => 'fake_pay_'.$nonce,
            'amount'                   => (int) config('plans.pro.price', 49900),
            'currency'                 => (string) config('billing.currency', 'PHP'),
            'period_start'             => now()->toIso8601String(),
            'period_end'               => now()->addMonth()->toIso8601String(),
        ], eventId: 'fake_evt_'.$nonce);

        $signature = $fake->signPayload($rawPayload);

        // Craft a sub-request whose raw body + X-Signature header mirror a genuine
        // inbound webhook, then invoke the controller directly. app()->call resolves
        // whatever method-injected dependencies WebhookController@handle declares.
        $subRequest = Request::create(
            route('billing.webhook'),
            'POST',
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => $signature],
            $rawPayload
        );

        app()->call(
            [app(WebhookController::class), 'handle'],
            ['request' => $subRequest]
        );
    }
}
