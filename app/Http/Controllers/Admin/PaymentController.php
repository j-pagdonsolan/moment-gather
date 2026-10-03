<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /**
     * Display a paginated list of payments with filters.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('view-payments');

        $query = Payment::query()->with('user');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('paid_at', [$request->from, $request->to]);
        } elseif ($request->filled('from')) {
            $query->where('paid_at', '>=', $request->from);
        } elseif ($request->filled('to')) {
            $query->where('paid_at', '<=', $request->to);
        }

        // Filter by provider
        if ($request->filled('provider')) {
            $query->where('provider', $request->provider);
        }

        // Filter by user (ID or email)
        if ($request->filled('user')) {
            $query->whereHas('user', function ($q) use ($request) {
                $userValue = $request->user;
                // Check if it's numeric (user ID) or email pattern
                if (is_numeric($userValue)) {
                    $q->where('id', $userValue);
                } else {
                    $q->where('email', 'LIKE', '%' . $userValue . '%');
                }
            });
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate(20);

        // Transform the data to match R17.3 requirements
        $payments->getCollection()->transform(function ($payment) {
            return [
                'id' => $payment->id,
                'user' => [
                    'name' => $payment->user->name,
                    'email' => $payment->user->email,
                ],
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'provider' => $payment->provider,
                'paid_at' => $payment->paid_at?->toISOString(),
                'created_at' => $payment->created_at->toISOString(),
                // NEVER expose provider_payment_id or metadata
            ];
        });

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => [
                'status' => $request->status,
                'from' => $request->from,
                'to' => $request->to,
                'provider' => $request->provider,
                'user' => $request->user,
            ],
        ]);
    }

    /**
     * Display payment detail.
     */
    public function show(Payment $payment): Response
    {
        Gate::authorize('view-payments');

        $payment->load('user', 'subscription');

        // Transform to R17.4 format
        $paymentData = [
            'id' => $payment->id,
            'user' => [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
                'email' => $payment->user->email,
            ],
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'provider' => $payment->provider,
            'paid_at' => $payment->paid_at?->toISOString(),
            'subscription_id' => $payment->subscription_id,
            'subscription_plan' => $payment->subscription?->plan,
            'created_at' => $payment->created_at->toISOString(),
            // NEVER expose provider_payment_id or metadata
        ];

        return Inertia::render('Admin/Payments/Show', [
            'payment' => $paymentData,
        ]);
    }
}
