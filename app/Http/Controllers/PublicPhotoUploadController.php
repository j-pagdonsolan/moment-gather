<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotosRequest;
use App\Jobs\ProcessPhoto;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicPhotoUploadController extends Controller
{
    /**
     * Accept guest photo uploads for an active, upload-enabled event.
     *
     * All trust-sensitive values (event association, storage path/filename,
     * mime type, status) are server-determined. Client-supplied event id,
     * path, filename, mime, and status are ignored. The original is stored
     * synchronously; optimization and thumbnailing are dispatched to a
     * background job (one per photo) via the queue.
     */
    public function store(StorePhotosRequest $request, string $slug): RedirectResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        abort_if(! $event->upload_enabled, 403, 'Photo uploads are currently closed.');

        // Event-level photo cap (abuse safeguard, not billing). Counts non-deleted
        // photos (SoftDeletes global scope excludes soft-deleted rows).
        $incoming = count($request->file('photos'));
        $currentCount = $event->photos()->count();
        $maxPerEvent = (int) config('uploads.max_per_event', 500);

        if ($currentCount + $incoming > $maxPerEvent) {
            Log::warning('Upload rejected: event photo cap exceeded', [
                'event_slug'    => $event->slug,
                'current_count' => $currentCount,
                'incoming'      => $incoming,
                'max_per_event' => $maxPerEvent,
            ]);

            abort(403, 'This event has reached its photo limit. Please contact the organizer.');
        }

        $disk = Storage::disk('public');

        foreach ($request->file('photos') as $file) {
            $uuid = (string) Str::uuid();
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $path = "events/{$event->uuid}/originals/{$uuid}.{$ext}";

            // Store the original before dispatching any job.
            $disk->putFileAs("events/{$event->uuid}/originals", $file, "{$uuid}.{$ext}");

            $absolute = $disk->path($path);
            $size     = @getimagesize($absolute);
            $width    = $size[0] ?? null;
            $height   = $size[1] ?? null;
            $mime     = $size['mime'] ?? $file->getMimeType();

            $photo = Photo::create([
                'event_id'          => $event->id,
                'uuid'              => $uuid,
                'original_filename' => $file->getClientOriginalName(),
                'original_path'     => $path,
                'mime_type'         => $mime,
                'file_size'         => $file->getSize(),
                'width'             => $width,
                'height'            => $height,
                'status'            => Photo::STATUS_PENDING,
            ]);

            // One background job per photo; processing happens on the queue.
            ProcessPhoto::dispatch($photo->id);
        }

        return back()->with(
            'success',
            'Your photos have been uploaded and are being processed.'
        );
    }
}
