# Design Document

## Overview

Phase 6 adds a **public photo gallery** to MomentGather. Event guests — with no account and no authentication — can open an *active* event's gallery, browse its successfully-uploaded (`ready`) photos in a responsive grid, open any photo in a full-screen lightbox, page through more photos with a "Load More" control, and download an individual photo.

The design is deliberately minimal and builds directly on the Phase 1–5 conventions already in the codebase:

- **Ready-only, event-scoped.** The gallery for an event returns *only* that event's photos and *only* photos with status `ready`. All filtering happens in the backend query through the existing `Event::photos()` relationship — never in React.
- **Direct public-disk Display URLs.** Each photo is rendered with an `<img>` whose `src` comes from `Storage::disk('public')->url($photo->original_path)` (a `/storage/...` URL). This is fast and exposes no server filesystem path. The `public` disk is already web-servable (`storage:link` done in Phase 5); Phase 5 upload storage is untouched.
- **Event-scoped, uuid-bound download.** A dedicated route resolves the active event by slug, resolves the photo by its `uuid` *within that event*, verifies the file exists, and streams it as a forced download (`Content-Disposition: attachment`) using the stored `original_filename`. Photos are referenced by `uuid`, never by database `id`.
- **Load More pagination.** Photos are paginated at a fixed **Page_Size of 24**. Load More issues an Inertia partial reload for the next page and appends new items to React state, deduping by `uuid`.
- **Graceful degradation.** A missing file shows a grid placeholder (client `onError`) and yields a safe 404 on download with no path leak. An event with no ready photos shows a friendly empty state.
- **Event-page integration.** The existing "View Gallery" button links to the gallery, and `PublicEventController@show` supplies a `photoCount` (ready-photo count) for the link.

**Scope guardrails honored:** local development only; the existing `public` disk; no S3/Spaces/CDN, no Redis/queues/Horizon, no image processing/resizing/thumbnails/EXIF, no video. **No database migration. No model changes** — the download resolves the photo manually inside the controller (see rationale in Backend Components), so no `getRouteKeyName` override is needed on `Photo`.

**Footprint:** two new public routes (gallery `GET`, download `GET`); two small controllers; one tweak to `PublicEventController@show`; a new `Gallery.tsx` page plus `PhotoGrid.tsx` / `PhotoCard.tsx` / `PhotoViewer.tsx` components; a small tweak to `Public/Event.tsx`; feature tests.

## Architecture

The gallery and download flows are two independent public request paths. Both reuse the exact event-resolution rule of the existing public event page (`slug` match, `status = 'active'`, not soft-deleted, via `firstOrFail()` → 404).

```mermaid
flowchart TD
    Guest([Guest — no auth])

    subgraph Gallery Flow
        Guest -->|GET /e/&#123;slug&#125;/gallery ?page=n| GC[PublicGalleryController@show]
        GC --> RE1{Resolve Active_Event<br/>slug + status=active + not deleted}
        RE1 -->|firstOrFail miss| N1[HTTP 404]
        RE1 -->|found| Q[event.photos&#40;&#41;<br/>where status = ready<br/>orderByDesc id<br/>paginate 24]
        Q --> MAP[through&#40;&#41;: map each photo →<br/>uuid, url = Storage::disk public ->url,<br/>filename, width, height, mime_type]
        MAP --> R[Inertia::render Public/Gallery<br/>event + photos + pagination]
        R --> PG[Public/Gallery page]
        PG --> GRID[PhotoGrid → PhotoCard<br/>img loading=lazy, onError→placeholder]
        GRID -->|click card| PV[PhotoViewer overlay<br/>prev / next / ESC / arrows / close]
        PG -->|Load More| LM[router.get ?page=n+1<br/>only: photos,pagination<br/>append + dedupe by uuid]
        LM --> Q
    end

    subgraph Download Flow
        PV -->|Download| D[GET /e/&#123;slug&#125;/photos/&#123;uuid&#125;/download]
        GRID -.->|optional| D
        D --> DC[PublicPhotoDownloadController@show]
        DC --> RE2{Resolve Active_Event}
        RE2 -->|miss| N2[HTTP 404]
        RE2 -->|found| RP{event.photos&#40;&#41;<br/>where uuid + status=ready<br/>firstOrFail}
        RP -->|miss: unknown uuid, other event,<br/>not ready, numeric id| N3[HTTP 404]
        RP -->|found| FX{Storage::disk public ->exists?}
        FX -->|no| N4[HTTP 404 — no path leak]
        FX -->|yes| STREAM[Storage::disk public ->download<br/>attachment; original_filename]
    end
```

Key architectural notes:

- **Load More is a partial Inertia reload**, not a fresh page load. The Gallery page keeps the appended photos in React state; each Load More issues `router.get(..., { page: n+1 }, { only: ['photos','pagination'], preserveState: true, preserveScroll: true })` and appends the newly returned `photos` filtered by uuids not already present.
- **The lightbox never navigates.** `PhotoViewer` is a client-side overlay over the current page, so gallery scroll position is preserved naturally when it closes.
- **Server-side scoping is the single source of truth.** Both the ready-and-event filter (gallery) and the photo∈event + ready check (download) live in the backend query. The client cannot widen them.

## Components and Interfaces

### Route additions (`routes/web.php`)

Both routes are registered **outside** the `auth`/`verified` group, alongside the existing public routes.

```php
use App\Http\Controllers\PublicGalleryController;
use App\Http\Controllers\PublicPhotoDownloadController;

// Public gallery. No authentication.
Route::get('/e/{slug}/gallery', [PublicGalleryController::class, 'show'])
    ->name('public.events.gallery');

// Public single-photo download. No authentication. Photo resolved by uuid
// inside the controller (see below) — no implicit route-model binding.
Route::get('/e/{slug}/photos/{photo}/download', [PublicPhotoDownloadController::class, 'show'])
    ->name('public.events.photos.download');
```

The download route uses a plain `{photo}` string segment. We do **not** use implicit route-model binding for the photo, because `Photo` has no `getRouteKeyName` override (it would bind by `id`, not `uuid`) and because binding alone would not enforce the photo-belongs-to-event rule. Resolving manually inside the controller gives us uuid-binding, event-scoping, and the ready check in a single query (see rationale below).

### `PublicGalleryController@show`

```php
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

        // Ready-only, event-scoped, stable order for consistent pagination.
        // orderByDesc('id') gives newest-first and a total order (id is a
        // strictly increasing surrogate key), so page boundaries never
        // shift between requests. event_id is selected because the hasMany
        // relationship constraint requires the FK column, but it is mapped
        // out of the payload below.
        $photos = $event->photos()
            ->where('status', Photo::STATUS_READY)
            ->orderByDesc('id')
            ->paginate(24, ['id', 'event_id', 'uuid', 'original_path', 'original_filename', 'width', 'height', 'mime_type']);

        // Transform items while preserving pagination metadata. Only the
        // client-needed fields cross the boundary — no id, no event_id, no
        // raw storage path.
        $photos->through(fn (Photo $photo) => [
            'uuid'     => $photo->uuid,
            'url'      => Storage::disk('public')->url($photo->original_path),
            'filename' => $photo->original_filename,
            'width'    => $photo->width,
            'height'   => $photo->height,
            'mime_type'=> $photo->mime_type,
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
```

Notes:

- **Ordering stability.** `orderByDesc('id')` yields newest-first and, because `id` is a strictly increasing surrogate key, a total order. This guarantees pages partition the ready set with no overlap and no shifting boundaries between the initial load and Load More requests. (`created_at` alone could tie; `id` cannot.)
- **Column selection.** We select only the columns needed to build the payload, satisfying field minimization. `id` and `event_id` are selected only because the paginator/relationship need them internally; they are dropped by the `through()` mapper and never reach the client.
- **`?page=` handling.** Laravel's `paginate()` reads the `page` query parameter automatically, so Load More's `router.get(..., { page: n+1 })` returns the correct slice with no extra code.

### `PublicPhotoDownloadController@show`

```php
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

        // One query enforces three rules at once:
        //  - the uuid matches (unknown uuid / numeric id => no match => 404)
        //  - the photo belongs to THIS event (other event's uuid => 404)
        //  - the photo is ready (pending/processing/failed/deleted => 404)
        $photo = $event->photos()
            ->where('uuid', $photo)
            ->where('status', Photo::STATUS_READY)
            ->firstOrFail();

        // Missing file => safe 404 (default Laravel 404, no path/credential
        // /stack detail in the body).
        abort_unless(Storage::disk('public')->exists($photo->original_path), 404);

        // Streamed response with Content-Disposition: attachment; filename=
        // original_filename. Never exposes a server filesystem path.
        return Storage::disk('public')->download($photo->original_path, $photo->original_filename);
    }
}
```

**Why manual resolution instead of route-model binding.** Resolving the photo through `$event->photos()->where('uuid', ...)->where('status', ready)->firstOrFail()`:

1. Binds by `uuid` (not `id`) without touching the `Photo` model — so a numeric `id` in the `{photo}` slot matches no row and 404s (requirement 7.8).
2. Enforces photo∈event in the same query — another event's photo uuid is not in this event's `photos()` set, so it 404s (requirement 7.4), with no separate ownership check.
3. Enforces `ready` — a non-ready photo 404s.

This is cleaner and safer than `{photo:uuid}` binding plus a separate `if ($photo->event_id !== $event->id) abort(404)` check. The `Photo` model stays untouched, honoring the "at most a `getRouteKeyName` override or a query scope" guardrail by using *neither*.

### `PublicEventController@show` tweak

Add the ready-photo count to the existing payload; everything else is unchanged.

```php
$payload = [
    'slug'           => $event->slug,
    'name'           => $event->name,
    'description'    => $event->description,
    'event_date'     => $event->event_date?->toDateString(),
    'location'       => $event->location,
    'upload_enabled' => $event->upload_enabled,
    // Phase 6: Ready_Photo_Count for the "View Gallery — N photos" link.
    'photoCount'     => $event->photos()->where('status', Photo::STATUS_READY)->count(),
];
```

`->count()` runs against the `photos()` relationship (already scoped to this event) with `status = 'ready'`; the `SoftDeletes` global scope excludes soft-deleted photos automatically. The existing fields (`slug`, `name`, `description`, `event_date`, `location`, `upload_enabled`) are preserved verbatim.

### Frontend components

- **`resources/js/pages/Public/Gallery.tsx`** — the gallery page (standalone Public/ layout). Seeds React state from props, renders the grid, empty state, Load More, and the lightbox.
- **`resources/js/components/PhotoGrid.tsx`** — responsive grid wrapper.
- **`resources/js/components/PhotoCard.tsx`** — a single lazy-loaded thumbnail button; handles `onError` placeholder.
- **`resources/js/components/PhotoViewer.tsx`** — full-screen lightbox overlay with prev/next/close, keyboard nav, and download.
- **`resources/js/pages/Public/Event.tsx`** — wire the existing "View Gallery" button to the gallery route and optionally show the count.

(Details in Frontend Components below.)

## Data Models

No database changes. The design introduces only client-side payload shapes.

### Backend payload (Inertia props for `Public/Gallery`)

```
event:      { slug: string, name: string }
photos:     Array<{ uuid, url, filename, width, height, mime_type }>
pagination: { current_page: number, last_page: number }
```

### TypeScript interfaces (`resources/js/types/models.ts`)

```typescript
export interface GalleryPhoto {
    uuid: string;
    url: string;
    filename: string;
    width: number | null;
    height: number | null;
    mime_type: string;
}

export interface GalleryPagination {
    current_page: number;
    last_page: number;
}

// Props for resources/js/pages/Public/Gallery.tsx
export interface GalleryPageProps {
    event: { slug: string; name: string };
    photos: GalleryPhoto[];
    pagination: GalleryPagination;
}
```

Extend the existing `PublicEvent` interface with the ready-photo count:

```typescript
export interface PublicEvent {
    slug: string;
    name: string;
    description: string | null;
    event_date: string | null;
    location: string | null;
    upload_enabled: boolean;
    photoCount: number; // Phase 6: Ready_Photo_Count
}
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

These properties are derived from the prework analysis. Client-only, layout, and pure-scope-guardrail criteria (responsive columns, lazy attribute, keyboard nav, empty-state copy, no-infra) are validated structurally/manually and are not listed as properties. Backend behavior below is expressed as example-based Laravel feature tests (see Testing Strategy); no property-based-testing library is added.

### Property 1: Gallery visibility soundness and completeness

*For any* event, `GET /e/{slug}/gallery` returns HTTP 200 rendering `Public/Gallery` **if and only if** that event has `status = 'active'` and is not soft-deleted; every other case (no matching slug, non-active status, soft-deleted) returns HTTP 404.

**Validates: Requirements 1.2, 1.3, 1.4, 1.5, 1.6**

### Property 2: Ready-and-event scoping of gallery photos

*For any* set of events and photos, the gallery response for a resolved Active_Event contains exactly the uuids of that event's `ready`, non-soft-deleted photos — no photo of another event and no photo with status `pending`, `processing`, `failed`, or `deleted` ever appears in the payload.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**

### Property 3: Payload minimization and Display URL form

*For any* photo serialized into the gallery payload, the item contains `uuid`, `url`, `filename`, `width`, `height`, and `mime_type`; it never contains the database `id`, `event_id`, or a raw filesystem `original_path`; and `url` is the public `/storage/...` form produced by `Storage::disk('public')->url($photo->original_path)`.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5**

### Property 4: Pagination partitions the ready set in stable order

*For any* Active_Event with N ready photos, each gallery response returns at most 24 items; the pages, taken over the fixed newest-first (`orderByDesc('id')`) order, are pairwise disjoint and their union is exactly the event's ready photos, so no photo is duplicated or omitted across pages and no single response returns all photos when N > 24.

**Validates: Requirements 4.1, 4.2, 4.7**

### Property 5: Pagination metadata signals a next page

*For any* gallery response, the payload includes `current_page` and `last_page` such that a further page exists precisely when `current_page < last_page`.

**Validates: Requirements 4.3**

### Property 6: Client append dedupe invariant

*For any* displayed photo set and any newly loaded page, appending the new page yields a set whose `uuid` values are all distinct — a photo already displayed is never added twice.

**Validates: Requirements 4.5**

### Property 7: Download success streams the original as an attachment

*For any* `ready` photo of an Active_Event whose file exists on the `public` disk, `GET /e/{slug}/photos/{uuid}/download` returns HTTP 200 with a `Content-Disposition: attachment` header carrying the photo's stored `original_filename`.

**Validates: Requirements 7.2, 7.3**

### Property 8: Download scoping and 404 completeness

*For any* download request, the response is HTTP 404 whenever the slug does not resolve to an Active_Event, the photo `uuid` matches no photo, the matched photo's `event_id` differs from the resolved event's id, the photo is not `ready`, or a database `id` is supplied in place of the `uuid`; only a uuid belonging to the resolved event and referring to a ready photo resolves.

**Validates: Requirements 7.4, 7.5, 7.6, 7.7, 7.8**

### Property 9: Missing-file download safety

*For any* download request that resolves a valid ready photo whose underlying file is absent from the `public` disk, the response is HTTP 404 and contains no filesystem path, storage credential, or server stack detail.

**Validates: Requirements 8.2, 8.3**

### Property 10: Byte-for-byte fidelity (no transformation)

*For any* photo download, the streamed bytes equal the stored original file's bytes exactly — no resizing, compression, thumbnailing, format conversion, or EXIF processing is applied.

**Validates: Requirements 11.3**

### Property 11: Ready-photo-count correctness

*For any* event, the `photoCount` supplied by `PublicEventController@show` equals the number of that event's `ready`, non-soft-deleted photos.

**Validates: Requirements 10.2, 10.3**

## Frontend Components

All frontend files use the existing conventions: `@/` = `resources/js/`, shadcn `Button` from `@/components/ui/button`, `lucide-react` icons, Tailwind v4, Inertia v3. The `Public/Gallery` page name starts with `Public/`, so `app.tsx` already returns a `null` layout (standalone), matching `Public/Event`.

### `Public/Gallery.tsx`

Seeds local state from Inertia props and manages the lightbox and Load More.

```tsx
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import PhotoGrid from '@/components/PhotoGrid';
import PhotoViewer from '@/components/PhotoViewer';
import { Button } from '@/components/ui/button';
import type { GalleryPageProps, GalleryPhoto } from '@/types';

export default function GalleryPage({ event, photos, pagination }: GalleryPageProps) {
    const [items, setItems] = useState<GalleryPhoto[]>(photos);
    const [currentPage, setCurrentPage] = useState(pagination.current_page);
    const [lastPage, setLastPage] = useState(pagination.last_page);
    const [loading, setLoading] = useState(false);
    const [viewerIndex, setViewerIndex] = useState<number | null>(null);

    const hasMore = currentPage < lastPage;

    const loadMore = () => {
        if (loading || !hasMore) return;
        setLoading(true);
        router.get(
            `/e/${event.slug}/gallery`,
            { page: currentPage + 1 },
            {
                only: ['photos', 'pagination'],
                preserveState: true,
                preserveScroll: true,
                // Inertia replaces props on partial reload; read the freshly
                // returned props from the visit and append deduped by uuid.
                onSuccess: (page) => {
                    const next = page.props.photos as GalleryPhoto[];
                    const meta = page.props.pagination as GalleryPageProps['pagination'];
                    setItems((prev) => {
                        const seen = new Set(prev.map((p) => p.uuid));
                        return [...prev, ...next.filter((p) => !seen.has(p.uuid))];
                    });
                    setCurrentPage(meta.current_page);
                    setLastPage(meta.last_page);
                },
                onFinish: () => setLoading(false),
            },
        );
    };

    return (
        <>
            <Head title={`${event.name} | Gallery | MomentGather`} />
            <div className="mx-auto w-full max-w-5xl px-4 py-10">
                <header className="mb-8 text-center">
                    <h1 className="text-3xl font-semibold text-foreground">{event.name}</h1>
                    <p className="text-muted-foreground">Shared Photo Gallery</p>
                </header>

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 py-20 text-center">
                        <p className="text-lg font-medium text-foreground">No photos yet</p>
                        <p className="text-muted-foreground">
                            Be the first to share a moment from this event.
                        </p>
                        <Button asChild size="lg">
                            {/* Links back to the event page — no second uploader here. */}
                            <Link href={`/e/${event.slug}`}>Upload Photos</Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        <PhotoGrid photos={items} onSelect={(i) => setViewerIndex(i)} />
                        {hasMore && (
                            <div className="mt-8 flex justify-center">
                                <Button variant="outline" size="lg" onClick={loadMore} disabled={loading}>
                                    {loading ? 'Loading…' : 'Load More'}
                                </Button>
                            </div>
                        )}
                    </>
                )}

                {viewerIndex !== null && (
                    <PhotoViewer
                        photos={items}
                        index={viewerIndex}
                        slug={event.slug}
                        onIndexChange={setViewerIndex}
                        onClose={() => setViewerIndex(null)}
                    />
                )}
            </div>
        </>
    );
}
```

Load More mechanism (concrete):

1. Initial `photos`/`pagination` come from Inertia props and seed `items`, `currentPage`, `lastPage`.
2. Load More issues a **partial reload** (`only: ['photos','pagination']`) for `page = currentPage + 1` with `preserveState` and `preserveScroll`.
3. In `onSuccess`, the freshly returned `photos` are read from `page.props`, filtered against the uuids already in state (dedupe), appended, and `currentPage`/`lastPage` updated.
4. The button hides once `currentPage >= lastPage`.

### `PhotoGrid.tsx` and `PhotoCard.tsx`

```tsx
// PhotoGrid.tsx
import PhotoCard from '@/components/PhotoCard';
import type { GalleryPhoto } from '@/types';

export default function PhotoGrid({
    photos,
    onSelect,
}: {
    photos: GalleryPhoto[];
    onSelect: (index: number) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {photos.map((photo, i) => (
                <PhotoCard key={photo.uuid} photo={photo} onClick={() => onSelect(i)} />
            ))}
        </div>
    );
}
```

```tsx
// PhotoCard.tsx
import { useState } from 'react';
import { ImageOff } from 'lucide-react';
import type { GalleryPhoto } from '@/types';

export default function PhotoCard({
    photo,
    onClick,
}: {
    photo: GalleryPhoto;
    onClick: () => void;
}) {
    const [broken, setBroken] = useState(false);

    return (
        <button
            type="button"
            onClick={onClick}
            className="group relative aspect-square overflow-hidden rounded-lg bg-muted"
        >
            {broken ? (
                <span className="flex h-full w-full flex-col items-center justify-center gap-1 text-muted-foreground">
                    <ImageOff className="h-6 w-6" />
                    <span className="text-xs">Photo unavailable</span>
                </span>
            ) : (
                <img
                    src={photo.url}
                    alt={photo.filename}
                    width={photo.width ?? undefined}
                    height={photo.height ?? undefined}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="h-full w-full object-cover transition group-hover:scale-105"
                />
            )}
        </button>
    );
}
```

- Responsive: `grid-cols-2` on mobile, `sm:grid-cols-3`, `lg:grid-cols-4` on desktop.
- `loading="lazy"` on every image (requirement 5.4).
- `width`/`height` are passed for intrinsic sizing; the `aspect-square` container reserves layout space to reduce shift (requirement 5.3).
- A per-card `broken` state swaps a single failed image for a "Photo unavailable" placeholder without affecting sibling cards (requirement 8.1).

### `PhotoViewer.tsx`

A full-screen fixed overlay (no navigation, so gallery scroll is preserved on close).

```tsx
import { useEffect } from 'react';
import { X, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import type { GalleryPhoto } from '@/types';

export default function PhotoViewer({
    photos,
    index,
    slug,
    onIndexChange,
    onClose,
}: {
    photos: GalleryPhoto[];
    index: number;
    slug: string;
    onIndexChange: (i: number) => void;
    onClose: () => void;
}) {
    const photo = photos[index];
    const goPrev = () => onIndexChange((index - 1 + photos.length) % photos.length);
    const goNext = () => onIndexChange((index + 1) % photos.length);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowLeft') goPrev();
            if (e.key === 'ArrowRight') goNext();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [index]);

    if (!photo) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90">
            <button type="button" onClick={onClose} aria-label="Close" className="absolute top-4 right-4 text-white">
                <X className="h-8 w-8" />
            </button>
            <button type="button" onClick={goPrev} aria-label="Previous" className="absolute left-2 text-white sm:left-6">
                <ChevronLeft className="h-10 w-10" />
            </button>

            <img src={photo.url} alt={photo.filename} className="max-h-[85vh] max-w-[90vw] object-contain" />

            <button type="button" onClick={goNext} aria-label="Next" className="absolute right-2 text-white sm:right-6">
                <ChevronRight className="h-10 w-10" />
            </button>

            {/* File download route — a plain anchor triggers the attachment response. */}
            <a
                href={`/e/${slug}/photos/${photo.uuid}/download`}
                className="absolute bottom-6 flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-white hover:bg-white/20"
            >
                <Download className="h-5 w-5" />
                Download
            </a>
        </div>
    );
}
```

- Close: X button and `Escape` (requirements 6.2, 6.3).
- Navigation: Chevron buttons and `ArrowLeft`/`ArrowRight` keys, wrapping via modulo (requirements 6.4–6.7).
- Download: a plain `<a href>` to the download route — since the route responds with `Content-Disposition: attachment`, the browser downloads rather than navigating, so the gallery stays mounted (requirement 6.8 scroll preservation).
- Works on mobile (tap buttons) and desktop (buttons + keys) (requirement 6.9).

### `Public/Event.tsx` tweak

Wire the "View Gallery" button and show the count.

```tsx
import { Link } from '@inertiajs/react';
// ...
<Button asChild size="lg" variant="outline" className="w-full">
    <Link href={`/e/${event.slug}/gallery`}>
        View Gallery{event.photoCount > 0 ? ` — ${event.photoCount} photos` : ''}
    </Link>
</Button>
```

The uploader and all existing fields are unchanged; only the button becomes a link and the optional count is read from `event.photoCount` (requirements 10.1, 10.2, 10.4).

## Error Handling

| Condition | Location | Response |
|---|---|---|
| Slug matches no event | Gallery + Download | HTTP 404 (`firstOrFail`) |
| Slug matches non-active event (archived/draft) | Gallery + Download | HTTP 404 (`where status=active` + `firstOrFail`) |
| Slug matches soft-deleted event | Gallery + Download | HTTP 404 (SoftDeletes global scope) |
| Photo `uuid` matches no photo | Download | HTTP 404 (`firstOrFail`) |
| Photo `uuid` belongs to a different event | Download | HTTP 404 (not in `$event->photos()`) |
| Photo is not `ready` (pending/processing/failed/deleted) | Download | HTTP 404 (`where status=ready`) |
| Numeric database `id` supplied in `{photo}` slot | Download | HTTP 404 (matched by `uuid`, not `id`) |
| Resolved photo's file missing on `public` disk | Download | HTTP 404 via `abort_unless(...exists...)`, **no path/credential/stack detail** |
| Photo's file missing on `public` disk | Gallery grid | Client `onError` → "Photo unavailable" placeholder; rest of grid unaffected |
| Event has zero ready photos | Gallery | HTTP 200 with empty `photos`; page shows empty state |

All 404s use Laravel's default not-found handling, whose body carries no filesystem path, storage credential, or stack trace in production, satisfying the no-leak requirement.

## Testing Strategy

**Approach.** Example-based Laravel feature tests exercise the backend behavior (the Correctness Properties are the source of truth for what to assert). No property-based-testing library is introduced — the codebase uses PHPUnit with `#[Test]`, `RefreshDatabase`, in-memory SQLite, `AssertableInertia`, and `Storage::fake('public')`. Client-side concerns (lightbox open/close, ESC/arrow keys, lazy attribute, Load More append/dedupe, responsive grid, `onError` placeholder, empty-state copy) are verified structurally/manually and are not automated here.

**Test files:** `tests/Feature/PhotoGalleryTest.php` and `tests/Feature/PhotoDownloadTest.php`.

**Test data.** `Photo::factory()` defaults to `status = ready`. Build ready photos with `Photo::factory()->for($event)->create(['status' => 'ready', 'original_path' => "events/{$event->uuid}/originals/{$uuid}.jpg"])`. For download tests, `Storage::fake('public')` then `Storage::disk('public')->put($path, 'bytes')`; omit the `put` to simulate a missing file.

### Gallery tests (`PhotoGalleryTest.php`)

| Test | Property | Assertion |
|---|---|---|
| `active_event_gallery_is_accessible_without_auth` | 1 | Guest `GET /e/{slug}/gallery` → 200, component `Public/Gallery` |
| `nonexistent_slug_gallery_returns_404` | 1 | 404 |
| `draft_event_gallery_returns_404` | 1 | 404 |
| `archived_event_gallery_returns_404` | 1 | 404 |
| `soft_deleted_event_gallery_returns_404` | 1 | 404 |
| `only_ready_photos_of_the_event_appear` | 2 | Create ready + pending + failed + processing + deleted + another event's ready; assert payload `photos` contains only the target event's ready uuids |
| `payload_excludes_id_event_id_and_raw_path` | 3 | `AssertableInertia`: each item has `uuid`/`url`/`filename`/`width`/`height`/`mime_type`; `url` starts with `/storage`; no `id`/`event_id`/`original_path` |
| `first_page_returns_24_of_30` | 4 | Seed 30 ready photos; page 1 → 24 items |
| `second_page_returns_remaining_6_no_overlap` | 4 | `?page=2` → 6 items; disjoint uuids from page 1; union = all 30 |
| `pagination_meta_signals_next_page` | 5 | Seed 30; page 1 `pagination.last_page = 2`, `current_page = 1`; page 2 `current_page = 2` |
| `empty_gallery_returns_200_with_no_photos` | 1, 9 | Event with no ready photos → 200, `photos` empty |

Representative example:

```php
#[Test]
public function only_ready_photos_of_the_event_appear(): void
{
    $event = Event::factory()->create(['status' => 'active', 'slug' => 'gala']);
    $ready = Photo::factory()->for($event)->create(['status' => 'ready']);
    Photo::factory()->for($event)->create(['status' => 'pending']);
    Photo::factory()->for($event)->create(['status' => 'failed']);
    $other = Event::factory()->create(['status' => 'active']);
    Photo::factory()->for($other)->create(['status' => 'ready']);

    $this->get('/e/gala/gallery')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Public/Gallery')
            ->has('photos', 1)
            ->where('photos.0.uuid', $ready->uuid)
            ->missing('photos.0.id')
            ->missing('photos.0.event_id')
            ->missing('photos.0.original_path')
        );
}
```

### Download tests (`PhotoDownloadTest.php`)

| Test | Property | Assertion |
|---|---|---|
| `download_streams_ready_photo_as_attachment` | 7, 10 | `Storage::fake` + `put`; 200, `Content-Disposition: attachment; filename=...original_filename`; streamed bytes equal stored bytes |
| `download_nonexistent_slug_returns_404` | 8 | 404 |
| `download_non_active_event_returns_404` | 8 | Archived event → 404 |
| `download_unknown_uuid_returns_404` | 8 | Random uuid → 404 |
| `download_other_events_photo_returns_404` | 8 | Photo of event B via event A's route → 404 |
| `download_non_ready_photo_returns_404` | 8 | Pending photo uuid → 404 |
| `download_by_numeric_id_returns_404` | 8 | Photo's numeric `id` in `{photo}` slot → 404 |
| `download_missing_file_returns_404` | 9 | Ready photo, file not `put` → 404 |

Representative examples:

```php
#[Test]
public function download_streams_ready_photo_as_attachment(): void
{
    Storage::fake('public');
    $event = Event::factory()->create(['status' => 'active', 'slug' => 'gala']);
    $photo = Photo::factory()->for($event)->create([
        'status'            => 'ready',
        'original_filename' => 'sunset.jpg',
        'original_path'     => "events/{$event->uuid}/originals/{$photo_uuid}.jpg",
    ]);
    Storage::disk('public')->put($photo->original_path, 'the-bytes');

    $response = $this->get("/e/gala/photos/{$photo->uuid}/download");
    $response->assertOk();
    $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    $this->assertStringContainsString('sunset.jpg', $response->headers->get('content-disposition'));
    $this->assertSame('the-bytes', $response->streamedContent());
}

#[Test]
public function download_other_events_photo_returns_404(): void
{
    Storage::fake('public');
    $eventA = Event::factory()->create(['status' => 'active', 'slug' => 'a']);
    $eventB = Event::factory()->create(['status' => 'active', 'slug' => 'b']);
    $photoB = Photo::factory()->for($eventB)->create(['status' => 'ready']);

    // Event A's route must not serve Event B's photo.
    $this->get("/e/a/photos/{$photoB->uuid}/download")->assertNotFound();
}
```

### Event-page integration test (extend `PublicEventPageTest.php`)

```php
#[Test]
public function public_event_payload_includes_ready_photo_count(): void
{
    $event = Event::factory()->create(['status' => 'active', 'slug' => 'counted']);
    Photo::factory()->count(3)->for($event)->create(['status' => 'ready']);
    Photo::factory()->for($event)->create(['status' => 'pending']); // excluded

    $this->get('/e/counted')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.photoCount', 3)
        );
}
```

**Property → test mapping (Requirement 16 / traceability):** Property 1 → visibility tests; Property 2 → `only_ready_photos_of_the_event_appear`; Property 3 → `payload_excludes_id_event_id_and_raw_path`; Property 4 → `first_page_returns_24_of_30` + `second_page_returns_remaining_6_no_overlap`; Property 5 → `pagination_meta_signals_next_page`; Property 6 → client dedupe (manual); Property 7 → `download_streams_ready_photo_as_attachment`; Property 8 → the download 404 suite; Property 9 → `download_missing_file_returns_404`; Property 10 → byte assertion in the attachment test; Property 11 → `public_event_payload_includes_ready_photo_count`.

**Regression:** the full existing Phase 1–5 suite (including `PublicEventPageTest`) must remain green — the only change to prior code is the additive `photoCount` field, which existing tests do not forbid.
