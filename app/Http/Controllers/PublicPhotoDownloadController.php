<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicPhotoDownloadController extends Controller
{
    /**
     * Stream a single ready photo of an active event as a forced download.
     * Resolves the event, then the photo by uuid *within that event* and
     * scoped to ready — a single query that simultaneously enforces
     * photo-belongs-to-event AND status=ready, 404-ing on any miss.
     */
    public function show(string $slug, string $photo): StreamedResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $photo = $event->photos()
            ->where('uuid', $photo)
            ->where('status', Photo::STATUS_READY)
            ->firstOrFail();

        abort_unless(Storage::disk('public')->exists($photo->original_path), 404);

        return Storage::disk('public')->download($photo->original_path, $photo->original_filename);
    }
}
