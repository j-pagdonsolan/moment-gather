<?php

namespace App\Http\Controllers\Billing;

use App\Billing\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CancelSubscriptionController extends Controller
{
    /**
     * Cancel the requesting user's active subscription at the end of the
     * current billing period.
     *
     * The subscription is always scoped to the authenticated user, so a user
     * can never cancel another user's subscription (IDOR-safe). The local
     * write sets end-of-period cancellation with explicit, server-side
     * attributes (never client mass assignment) and preserves
     * `current_period_end` so the grace period (isActiveNow/onGracePeriod)
     * keeps granting Pro until the period ends (R19.3).
     */
    public function store(Request $request, PaymentProviderInterface $provider): RedirectResponse
    {
        $user = $request->user();

        $subscription = $user->subscriptions()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->latest()
            ->first();

        if (! $subscription) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'You do not have an active subscription to cancel.',
            ]);

            return back();
        }

        // Provider-side cancellation (fake provider is a no-op).
        $provider->cancelSubscription($subscription);

        // Local end-of-period cancellation. Explicit attributes are set
        // server-side; current_period_end is intentionally left intact so the
        // grace period still grants Pro until the period ends (R19.3).
        $subscription->update([
            'status' => Subscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Your subscription will be canceled at the end of the current period. You keep Pro until then.',
        ]);

        return back();
    }
}
