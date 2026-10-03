<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    /**
     * Display a paginated list of all events across all organizers.
     *
     * Supports:
     *   - Search by name/slug (case-insensitive LIKE)
     *   - Filter by status (active/draft/archived)
     *   - Filter by owner (user ID or email)
     *
     * @param  Request  $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-events');

        $query = Event::query()->with('user')->withCount('photos');

        // Search by name or slug (case-insensitive)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('slug', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by owner (user ID or email)
        if ($owner = $request->input('owner')) {
            // Check if owner is numeric (ID) or string (email)
            if (is_numeric($owner)) {
                $query->where('user_id', $owner);
            } else {
                $query->whereHas('user', function ($q) use ($owner) {
                    $q->where('email', 'LIKE', "%{$owner}%");
                });
            }
        }

        $events = $query->latest()->paginate(20)->withQueryString();

        // Transform results to match AdminEvent type
        $events->through(function ($event) {
            return [
                'id'          => $event->id,
                'uuid'        => $event->uuid,
                'name'        => $event->name,
                'slug'        => $event->slug,
                'status'      => $event->status,
                'owner'       => [
                    'name'  => $event->user->name,
                    'email' => $event->user->email,
                ],
                'photo_count' => $event->photos_count,
                'created_at'  => $event->created_at->toISOString(),
            ];
        });

        return Inertia::render('Admin/Events/Index', [
            'events'  => $events,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'owner'  => $request->input('owner'),
            ],
        ]);
    }

    /**
     * Display full detail for a single event.
     *
     * @param  Event  $event  Route model binding on uuid
     * @return Response
     */
    public function show(Event $event): Response
    {
        Gate::authorize('manage-events');

        $event->load('user');
        $event->loadCount('photos');

        return Inertia::render('Admin/Events/Show', [
            'event' => [
                'id'             => $event->id,
                'uuid'           => $event->uuid,
                'name'           => $event->name,
                'slug'           => $event->slug,
                'description'    => $event->description,
                'event_date'     => $event->event_date?->toDateString(),
                'location'       => $event->location,
                'status'         => $event->status,
                'upload_enabled' => $event->upload_enabled,
                'owner'          => [
                    'id'    => $event->user->id,
                    'name'  => $event->user->name,
                    'email' => $event->user->email,
                ],
                'photo_count'    => $event->photos_count,
                'created_at'     => $event->created_at->toISOString(),
                'updated_at'     => $event->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Archive an event (set status to 'archived').
     *
     * @param  Event  $event
     * @param  Request  $request
     * @param  AuditLogger  $logger
     * @return RedirectResponse
     */
    public function archive(Event $event, Request $request, AuditLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-events');

        $event->update(['status' => 'archived']);

        $logger->log(
            $request->user(),
            'event_archived',
            'Event',
            $event->id,
            "Event '{$event->name}' archived.",
            ['event_uuid' => $event->uuid]
        );

        return redirect()->back()->with('success', 'Event archived.');
    }

    /**
     * Soft-delete an event.
     *
     * @param  Event  $event
     * @param  Request  $request
     * @param  AuditLogger  $logger
     * @return RedirectResponse
     */
    public function destroy(Event $event, Request $request, AuditLogger $logger): RedirectResponse
    {
        Gate::authorize('manage-events');

        $logger->log(
            $request->user(),
            'event_deleted',
            'Event',
            $event->id,
            "Event '{$event->name}' soft-deleted.",
            ['event_uuid' => $event->uuid]
        );

        $event->delete();

        return redirect()->route('admin.events.index')->with('success', 'Event deleted.');
    }
}
