<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

class PhotoRemovalService
{
    /**
     * Soft-delete the photo record and remove its files from storage.
     *
     * Steps:
     *   1. Collect non-null storage paths from the photo.
     *   2. Soft-delete the photo record via SoftDeletes::delete().
     *   3. Delete collected paths from the public disk (mirrors ProcessPhoto::failed() pattern).
     *
     * @param  Photo  $photo  The photo to remove. Must have SoftDeletes.
     * @return void
     */
    public function remove(Photo $photo): void
    {
        // Step 1: collect non-null paths before the record is soft-deleted.
        $paths = array_filter([
            $photo->original_path,
            $photo->optimized_path,
            $photo->thumbnail_path,
        ]);

        // Step 2: soft-delete the database record.
        $photo->delete();

        // Step 3: remove files from storage (only when there are paths to delete).
        if (! empty($paths)) {
            Storage::disk('public')->delete(array_values($paths));
        }
    }
}
