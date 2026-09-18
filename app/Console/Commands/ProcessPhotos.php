<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\PhotoProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessPhotos extends Command
{
    protected $signature   = 'photos:process';
    protected $description = 'Generate optimized and thumbnail WebP variants for ready photos that lack them.';

    public function handle(PhotoProcessor $processor): int
    {
        $disk      = Storage::disk('public');
        $processed = 0;
        $skipped   = 0;

        Photo::query()
            ->where('status', Photo::STATUS_READY)
            ->where(function ($q): void {
                $q->whereNull('optimized_path')->orWhereNull('thumbnail_path');
            })
            ->with('event:id,uuid')
            ->chunkById(50, function ($photos) use ($processor, $disk, &$processed, &$skipped): void {
                foreach ($photos as $photo) {
                    if (! $disk->exists($photo->original_path)) {
                        $this->warn("Skipping {$photo->uuid}: original missing.");
                        $skipped++;
                        continue;
                    }

                    $paths = $processor->process($photo->original_path, $photo->event->uuid, $photo->uuid);
                    $photo->update([
                        'optimized_path' => $paths['optimized_path'],
                        'thumbnail_path' => $paths['thumbnail_path'],
                    ]);
                    $processed++;
                }
            });

        $this->info("Processed {$processed} photo(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
