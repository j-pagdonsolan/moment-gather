<?php

namespace App\Services;

use App\Models\Event;

class SlugGenerator
{
    /**
     * Generate a unique slug from an event name.
     *
     * Returns an empty string if the name contains no alphanumeric characters.
     * The caller should fall back to generateFromUuid() in that case.
     */
    public function generate(string $name, ?int $excludeId = null): string
    {
        $base = $this->slugify($name);

        if ($base === '') {
            return '';
        }

        return $this->makeUnique($base, $excludeId);
    }

    /**
     * Generate a unique slug from the first 12 hex characters of a UUID.
     *
     * Used as a fallback when the event name contains no alphanumeric characters.
     */
    public function generateFromUuid(string $uuid): string
    {
        $base = substr(str_replace('-', '', $uuid), 0, 12);

        return $this->makeUnique($base, null);
    }

    /**
     * Convert a name into a URL-safe slug.
     *
     * - Lowercases the entire string
     * - Replaces any sequence of non-alphanumeric characters with a single hyphen
     * - Trims leading and trailing hyphens
     *
     * No database access is performed here.
     */
    private function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Ensure a slug is unique across non-deleted events.
     *
     * Starts with $base and appends -2, -3, … until a free slot is found.
     * When $excludeId is provided the event with that primary key is ignored,
     * which allows re-generation without colliding with the event itself.
     */
    private function makeUnique(string $base, ?int $excludeId): string
    {
        $slug = $base;
        $suffix = 2;

        while (true) {
            $query = Event::withoutTrashed()->where('slug', $slug);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }
}
