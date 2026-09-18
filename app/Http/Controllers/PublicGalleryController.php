<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PublicGalleryController extends Controller
{
    /**
     * Public gallery for an active event. Ready-only, event-scoped,
     * paginated at 24. Resolves the event by the same visibility rule as
     * the public event page (slug + status=active + not soft-deleted).
     */
    public function show(string $slug): Response
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $photos = $event->photos()
            ->where('status', Photo::STATUS_READY)
            ->orderByDesc('id')
            ->paginate(24, ['id', 'event_id', 'uuid', 'original_path', 'original_filename', 'width', 'height', 'mime_type']);

        $photos->through(fn (Photo $photo) => [
            'uuid'      => $photo->uuid,
            'url'       => Storage::disk('public')->url($photo->original_path),
            'filename'  => $photo->original_filename,
            'width'     => $photo->width,
            'height'    => $photo->height,
            'mime_type' => $photo->mime_type,
        ]);

        return Inertia::render('Public/Gallery', [
            'event' => [
                'slug' => $event->slug,
                'name' => $event->name,
            ],
            'photos'     => $photos->items(),
            'pagination' => [
                'current_page' => $photos->currentPage(),
                'last_page'    => $photos->lastPage(),
            ],
        ]);
    }
}
