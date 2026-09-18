<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Services\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function __construct(private SlugGenerator $slugGenerator) {}

    /**
     * Display a listing of the authenticated user's events.
     */
    public function index(Request $request): Response
    {
        $events = Event::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Events/Index', ['events' => $events]);
    }

    /**
     * Show the form for creating a new event.
     */
    public function create(): Response
    {
        return Inertia::render('Events/Create');
    }

    /**
     * Store a newly created event in storage.
     */
    public function store(StoreEventRequest $request): RedirectResponse
    {
        return DB::transaction(function () use ($request): RedirectResponse {
            $data = $request->validated();

            $uuid = (string) Str::uuid();
            $base = $this->slugGenerator->generate($data['name']);
            $slug = $base !== '' ? $base : $this->slugGenerator->generateFromUuid($uuid);

            $event = $request->user()->events()->create([
                ...$data,
                'uuid'           => $uuid,
                'slug'           => $slug,
                'status'         => 'active',
                'upload_enabled' => true,
            ]);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Event created.']);

            return to_route('events.show', $event);
        });
    }

    /**
     * Display the specified event.
     */
    public function show(Request $request, Event $event): Response
    {
        $this->authorize('view', $event);

        return Inertia::render('Events/Show', [
            'event'     => $event,
            'publicUrl' => route('public.events.show', ['slug' => $event->slug]),
        ]);
    }

    /**
     * Show the form for editing the specified event.
     */
    public function edit(Request $request, Event $event): Response
    {
        $this->authorize('update', $event);

        return Inertia::render('Events/Edit', ['event' => $event]);
    }

    /**
     * Update the specified event in storage.
     */
    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event updated.']);

        return to_route('events.show', $event);
    }

    /**
     * Remove the specified event from storage (soft delete).
     */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event deleted.']);

        return to_route('events.index');
    }
}
