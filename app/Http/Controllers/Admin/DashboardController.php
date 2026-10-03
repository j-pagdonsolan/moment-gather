<?php

namespace App\Http\Controllers\Admin;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    /**
     * Display the admin dashboard with aggregate system statistics.
     *
     * All queries use aggregate COUNT/GROUP BY to avoid N+1 patterns.
     * Returns data shaped as AdminDashboardStats for the frontend.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-users');

        // User statistics
        $totalUsers = User::count();
        $activeUsers = User::where('is_active', true)->count();
        $inactiveUsers = User::where('is_active', false)->count();
        $superAdminCount = User::whereHas('roles', function ($query) {
            $query->where('name', 'super_admin');
        })->count();

        // Event statistics
        $totalEvents = Event::count();
        $eventsByStatus = Event::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Photo statistics
        $totalPhotos = Photo::count();
        $photosByStatus = Photo::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Subscription statistics
        // Free-plan users: users without any active subscription (isPro() would return false)
        // We need to identify users who either have no subscriptions or none are active
        $allUserIds = User::pluck('id');
        $proUserIds = Subscription::whereIn('status', ['active', 'canceled'])
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere(function ($q) {
                        $q->where('status', 'canceled')
                            ->where('cancel_at_period_end', true)
                            ->whereNotNull('current_period_end')
                            ->where('current_period_end', '>', now());
                    });
            })
            ->distinct()
            ->pluck('user_id')
            ->unique();

        $freePlanUsers = $allUserIds->diff($proUserIds)->count();
        $proPlanUsers = $proUserIds->count();

        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $canceledSubscriptions = Subscription::where('status', 'canceled')->count();

        // Payment statistics
        $succeededPayments = Payment::where('status', 'succeeded')->count();
        $failedPayments = Payment::where('status', 'failed')->count();

        $recentPayments = Payment::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(function ($payment) {
                return [
                    'id'       => $payment->id,
                    'user'     => [
                        'id'    => $payment->user->id,
                        'name'  => $payment->user->name,
                        'email' => $payment->user->email,
                    ],
                    'amount'   => $payment->amount,
                    'currency' => $payment->currency,
                    'status'   => $payment->status,
                    'paid_at'  => $payment->paid_at?->toISOString(),
                    'created_at' => $payment->created_at->toISOString(),
                ];
            });

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => [
                    'total'             => $totalUsers,
                    'active'            => $activeUsers,
                    'inactive'          => $inactiveUsers,
                    'super_admin_count' => $superAdminCount,
                ],
                'events' => [
                    'total'    => $totalEvents,
                    'active'   => $eventsByStatus['active'] ?? 0,
                    'draft'    => $eventsByStatus['draft'] ?? 0,
                    'archived' => $eventsByStatus['archived'] ?? 0,
                ],
                'photos' => [
                    'total'      => $totalPhotos,
                    'by_status'  => [
                        'pending'    => $photosByStatus['pending'] ?? 0,
                        'processing' => $photosByStatus['processing'] ?? 0,
                        'ready'      => $photosByStatus['ready'] ?? 0,
                        'failed'     => $photosByStatus['failed'] ?? 0,
                    ],
                ],
                'subscriptions' => [
                    'free_plan_users' => $freePlanUsers,
                    'pro_plan_users'  => $proPlanUsers,
                    'active'          => $activeSubscriptions,
                    'canceled'        => $canceledSubscriptions,
                ],
                'payments' => [
                    'succeeded'    => $succeededPayments,
                    'failed_count' => $failedPayments,
                    'recent'       => $recentPayments,
                ],
            ],
        ]);
    }
}
