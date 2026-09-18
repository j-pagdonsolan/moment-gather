<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotosRequest;
use App\Models\Event;
use App\Models\Photo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
    public function store(StorePhotosRequest $request, string $slug): RedirectResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        abort_if(! $event->upload_enabled, 403, 'Photo uploads are currently closed.');

        foreach ($request->file('photos') as $file) {
            $uuid = (string) Str::uuid();
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $path = "events/{$event->uuid}/originals/{$uuid}.{$ext}";

            Storage::disk('public')->putFileAs(
                "events/{$event->uuid}/originals",
                $file,
                "{$uuid}.{$ext}"
            );

            $absolute = Storage::disk('public')->path($path);
            $size     = @getimagesize($absolute);
            $width    = $size[0] ?? null;
            $height   = $size[1] ?? null;
            $mime     = $size['mime'] ?? $file->getMimeType();

            try {
                DB::transaction(function () use ($event, $uuid, $file, $path, $mime, $width, $height): void {
                    Photo::create([
                        'event_id'          => $event->id,
                        'uuid'              => $uuid,
                        'original_filename' => $file->getClientOriginalName(),
                        'original_path'     => $path,
                        'mime_type'         => $mime,
                        'file_size'         => $file->getSize(),
                        'width'             => $width,
                        'height'            => $height,
                        'status'            => Photo::STATUS_READY,
                    ]);
                });
            } catch (\Throwable $e) {
                Storage::disk('public')->delete($path);

                return back()
                    ->withErrors(['photos' => 'We could not save your photos. Please try again.'])
                    ->withInput();
            }
        }

        return back()->with('success', 'Your photos have been added to the event!');
    }
}
