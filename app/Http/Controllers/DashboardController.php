<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard with event statistics and recent events.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $stats = [
            'totalEvents'    => Event::where('user_id', $userId)->count(),
            'activeEvents'   => Event::where('user_id', $userId)->where('status', 'active')->count(),
            'archivedEvents' => Event::where('user_id', $userId)->where('status', 'archived')->count(),
        ];

        $recentEvents = Event::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['uuid', 'name', 'status', 'event_date', 'created_at']);

        return Inertia::render('dashboard', [
            'stats'        => $stats,
            'recentEvents' => $recentEvents,
        ]);
    }
}
