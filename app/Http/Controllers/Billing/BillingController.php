<?php

namespace App\Http\Controllers\Billing;

use App\Billing\BillingService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show the authenticated user's billing settings page.
     *
     * All values are derived from the backend authoritative {@see BillingService}
     * and scoped to the current user. Only the PUBLIC payment key is exposed to
     * the frontend — the secret key and webhook secret are NEVER included in the
     * Inertia props. Payment history is mapped to a safe shape that omits raw
     * provider metadata (R13, R20, R24, R22.5).
     */
    public function show(Request $request, BillingService $billing): Response
    {
        $user = $request->user();

        $plan = $billing->currentPlan($user);

        // Most recent subscription for the user, if any.
        $subscription = $user->subscriptions()->latest()->first();

        $usage = $billing->usageSummary($user);

        // Owner-scoped payment history mapped to a safe, minimal shape.
        // Never expose metadata or raw provider fields beyond the reference id.
        $payments = $user->payments()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Payment $payment): array => [
                'id'        => $payment->id,
                'date'      => $payment->paid_at?->toDateString() ?? $payment->created_at->toDateString(),
                'amount'    => $payment->amount,
                'currency'  => $payment->currency,
                'status'    => $payment->status,
                'reference' => $payment->provider_payment_id,
            ])
            ->all();

        return Inertia::render('settings/billing', [
            'plan' => [
                'slug'              => $plan->slug,
                'name'              => $plan->name,
                'price'             => $plan->price,
                'billingInterval'   => $plan->billingInterval,
                'maxActiveEvents'   => $plan->maxActiveEvents,
                'maxPhotosPerEvent' => $plan->maxPhotosPerEvent,
                'maxStorageBytes'   => $plan->maxStorageBytes,
            ],
            'isPro' => $billing->isPro($user),
            'subscription' => $subscription ? [
                'status'             => $subscription->status,
                'cancelAtPeriodEnd'  => $subscription->cancel_at_period_end,
                'currentPeriodEnd'   => $subscription->current_period_end?->toDateString(),
                'onGracePeriod'      => $subscription->onGracePeriod(),
            ] : null,
            'usage'     => $usage,
            'payments'  => $payments,
            'currency'  => config('billing.currency'),
            // PUBLIC key only — NEVER the secret key or webhook secret.
            'publicKey' => config('billing.public_key'),
        ]);
    }
}
