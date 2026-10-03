<?php

namespace App\Http\Controllers\Admin;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController
{
    /**
     * Display a paginated list of all subscriptions across all users.
     *
     * Supports filtering by status, plan slug, and user (by ID or email).
     * Each record includes user name/email, plan, provider, status, period end,
     * and created_at. NEVER exposes provider_subscription_id.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-subscriptions');

        $query = Subscription::with('user:id,name,email');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by plan slug
        if ($request->filled('plan')) {
            $query->where('plan', $request->input('plan'));
        }

        // Filter by user (ID or email LIKE)
        if ($request->filled('user')) {
            $userFilter = $request->input('user');
            $query->whereHas('user', function ($q) use ($userFilter) {
                // Try as ID first, then as email partial match
                if (is_numeric($userFilter)) {
                    $q->where('id', $userFilter);
                } else {
                    $q->where('email', 'LIKE', "%{$userFilter}%");
                }
            });
        }

        $subscriptions = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(function ($subscription) {
                return [
                    'id'                 => $subscription->id,
                    'user'               => [
                        'id'    => $subscription->user->id,
                        'name'  => $subscription->user->name,
                        'email' => $subscription->user->email,
                    ],
                    'plan'               => $subscription->plan,
                    'provider'           => $subscription->provider,
                    'status'             => $subscription->status,
                    'current_period_end' => $subscription->current_period_end?->toISOString(),
                    'created_at'         => $subscription->created_at->toISOString(),
                    // NEVER include provider_subscription_id
                ];
            });

        return Inertia::render('Admin/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters'       => [
                'status' => $request->input('status'),
                'plan'   => $request->input('plan'),
                'user'   => $request->input('user'),
            ],
        ]);
    }

    /**
     * Display detailed information about a single subscription.
     *
     * Loads the full subscription record with user relationship.
     * NEVER exposes provider_subscription_id.
     */
    public function show(Subscription $subscription): Response
    {
        Gate::authorize('manage-subscriptions');

        $subscription->load('user:id,name,email');

        return Inertia::render('Admin/Subscriptions/Show', [
            'subscription' => [
                'id'                     => $subscription->id,
                'user'                   => [
                    'id'    => $subscription->user->id,
                    'name'  => $subscription->user->name,
                    'email' => $subscription->user->email,
                ],
                'plan'                   => $subscription->plan,
                'provider'               => $subscription->provider,
                'status'                 => $subscription->status,
                'current_period_start'   => $subscription->current_period_start?->toISOString(),
                'current_period_end'     => $subscription->current_period_end?->toISOString(),
                'cancel_at_period_end'   => $subscription->cancel_at_period_end,
                'canceled_at'            => $subscription->canceled_at?->toISOString(),
                'created_at'             => $subscription->created_at->toISOString(),
                'updated_at'             => $subscription->updated_at->toISOString(),
                // NEVER include provider_subscription_id
            ],
        ]);
    }
}
