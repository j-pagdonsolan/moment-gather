<?php

namespace App\Http\Controllers\Billing;

use App\Billing\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCheckoutRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    /**
     * Start a checkout session for the requested plan and redirect the
     * organizer to the provider's hosted checkout URL.
     */
    public function store(CreateCheckoutRequest $request, PaymentProviderInterface $provider): RedirectResponse
    {
        $session = $provider->createCheckoutSession($request->user(), $request->validated('plan'));

        // For the fake provider this is a local named-route URL; a real
        // provider would return an external hosted-checkout URL.
        return redirect($session->url);
    }

    /**
     * Verified return after a successful checkout. This is a UX convenience
     * only — the authoritative subscription state change happens via the
     * webhook, so this method must NOT grant Pro or write subscription state.
     */
    public function success(Request $request): RedirectResponse
    {
        Inertia::flash('toast', [
            'type'    => 'success',
            'message' => 'Welcome to Pro! Your subscription is being activated.',
        ]);

        return redirect()->route('billing.show');
    }

    /**
     * Verified return after a canceled checkout. Flashes a neutral toast and
     * returns to the Billing page. Does NOT grant Pro.
     */
    public function cancel(Request $request): RedirectResponse
    {
        Inertia::flash('toast', [
            'type'    => 'info',
            'message' => 'Checkout canceled. You are still on your current plan.',
        ]);

        return redirect()->route('billing.show');
    }
}
