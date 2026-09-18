<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotosRequest;
use App\Models\Event;
use App\Models\Photo;
use App\Services\PhotoProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicPhotoUploadController extends Controller
{
    /**
     * Accept guest photo uploads for an active, upload-enabled event.
     *
     * All trust-sensitive values (event association, storage path/filename,
     * mime type, status) are server-determined. Client-supplied event id,
     * path, filename, mime, and status are ignored.
     */
    public function store(StorePhotosRequest $request, string $slug, PhotoProcessor $processor): RedirectResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        abort_if(! $event->upload_enabled, 403, 'Photo uploads are currently closed.');

        $disk     = Storage::disk('public');
        $failures = 0;

        foreach ($request->file('photos') as $file) {
            $uuid = (string) Str::uuid();
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $path = "events/{$event->uuid}/originals/{$uuid}.{$ext}";

            // 1. Store the original (unchanged from Phase 5).
            $disk->putFileAs("events/{$event->uuid}/originals", $file, "{$uuid}.{$ext}");

            $absolute = $disk->path($path);
            $size     = @getimagesize($absolute);
            $width    = $size[0] ?? null;
            $height   = $size[1] ?? null;
            $mime     = $size['mime'] ?? $file->getMimeType();

            // 2. Create the row in processing state.
            $photo = Photo::create([
                'event_id'          => $event->id,
                'uuid'              => $uuid,
                'original_filename' => $file->getClientOriginalName(),
                'original_path'     => $path,
                'mime_type'         => $mime,
                'file_size'         => $file->getSize(),
                'width'             => $width,
                'height'            => $height,
                'status'            => Photo::STATUS_PROCESSING,
            ]);

            // 3. Process, then mark ready — or clean up and mark failed.
            try {
                $paths = $processor->process($path, $event->uuid, $uuid);

                $photo->update([
                    'optimized_path' => $paths['optimized_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'status'         => Photo::STATUS_READY,
                ]);
            } catch (\Throwable $e) {
                $disk->delete([
                    "events/{$event->uuid}/optimized/{$uuid}.webp",
                    "events/{$event->uuid}/thumbnails/{$uuid}.webp",
                ]);

                $photo->update(['status' => Photo::STATUS_FAILED]);
                $failures++;
            }
        }

        if ($failures > 0) {
            return back()->with(
                'success',
                'Your photos were uploaded. Some could not be processed and were skipped.'
            );
        }

        return back()->with('success', 'Your photos have been added to the event!');
    }
}
