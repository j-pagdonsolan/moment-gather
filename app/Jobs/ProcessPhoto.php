<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\PhotoProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum processing attempts before the job is marked failed.
     */
    public int $tries = 3;

    /**
     * Carry the primitive id (not the model) so a deleted photo does not
     * break job deserialization; the model is re-fetched in handle().
     */
    public function __construct(public int $photoId)
    {
    }

    /**
     * Finite retry backoff in seconds between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Process a single photo: original -> optimized + thumbnail -> ready.
     * Idempotent and safe to run more than once.
     */
    public function handle(PhotoProcessor $processor): void
    {
        $photo = Photo::find($this->photoId);

        if (! $photo) {
            return;
        }

        if ($photo->status === Photo::STATUS_READY
            && $photo->optimized_path
            && $photo->thumbnail_path) {
            return;
        }

        if (! Storage::disk('public')->exists($photo->original_path)) {
            $photo->update(['status' => Photo::STATUS_FAILED]);

            return;
        }

        $photo->update(['status' => Photo::STATUS_PROCESSING]);

        $paths = $processor->process(
            $photo->original_path,
            $photo->event->uuid,
            $photo->uuid,
        );

        $photo->update([
            'optimized_path' => $paths['optimized_path'],
            'thumbnail_path' => $paths['thumbnail_path'],
            'status'         => Photo::STATUS_READY,
        ]);
    }

    /**
     * Runs after retries are exhausted. Marks the photo failed, removes any
     * partial processed files, and logs without leaking detail.
     */
    public function failed(?Throwable $exception): void
    {
        $photo = Photo::find($this->photoId);

        if (! $photo) {
            return;
        }

        if ($photo->event) {
            Storage::disk('public')->delete([
                "events/{$photo->event->uuid}/optimized/{$photo->uuid}.webp",
                "events/{$photo->event->uuid}/thumbnails/{$photo->uuid}.webp",
            ]);
        }

        $photo->update(['status' => Photo::STATUS_FAILED]);

        Log::error('Photo processing failed', [
            'photo_id'  => $this->photoId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
