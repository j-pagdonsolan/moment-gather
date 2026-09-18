<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class PublicEventController extends Controller
{
    /**
     * Display the public attendee-facing page for an active event.
     *
     * Resolves the event by its slug, scoped to the visibility rule:
     * status = 'active' AND not soft-deleted. Any other case yields a 404.
     *
     * firstOrFail() throws ModelNotFoundException (=> HTTP 404) when no
     * matching row exists, covering nonexistent slugs and non-active
     * statuses. The SoftDeletes global scope automatically appends
     * "deleted_at is null", so soft-deleted events 404 without an explicit
     * clause. Query parameters are never consulted.
     */
    public function show(string $slug): Response
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Narrow, attendee-only payload. Organizer-private fields
        // (id, uuid, user_id, slug, timestamps, user relationship) are
        // intentionally excluded and never sent to the frontend.
        $payload = [
            'name'           => $event->name,
            'description'    => $event->description,
            'event_date'     => $event->event_date?->toDateString(),
            'location'       => $event->location,
            'upload_enabled' => $event->upload_enabled,
        ];

        return Inertia::render('Public/Event', [
            'event' => $payload,
        ]);
    }
}
