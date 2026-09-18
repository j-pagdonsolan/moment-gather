# Implementation Plan: Photo Gallery (MomentGather Phase 6)

## Overview

Convert the Photo Gallery design into incremental, self-contained coding steps. The feature adds a public, unauthenticated photo gallery for active events: two backend routes/controllers (gallery + single-photo download), a `photoCount` tweak to the existing public event page, TypeScript types, a `Public/Gallery` page with grid/card/lightbox components, a "View Gallery" link on `Public/Event.tsx`, and PHPUnit feature tests.

No new packages, no migration, no model changes. All Phase 1–5 tests must continue to pass. Filtering (event-scoped, `ready`-only) lives entirely in the backend query. Photos are always referenced by `uuid`, never by database `id`. Display uses `Storage::disk('public')->url($photo->original_path)`; download streams the stored original as `Content-Disposition: attachment`.

Backend tests are example-based PHPUnit feature tests that assert the design's Correctness Properties (P1–P11). Client-side concerns (lightbox keys, lazy attribute, Load More append/dedupe, responsive grid, `onError` placeholder, empty-state copy) are structural/manual and are not automated. Test sub-tasks are marked optional with `*`.

## Tasks

- [ ] 1. Backend gallery + download controllers and routes
  - [ ] 1.1 Create `app/Http/Controllers/PublicGalleryController.php`
    - Add `show(string $slug): \Inertia\Response`.
    - Resolve the active event: `Event::query()->where('slug', $slug)->where('status', 'active')->firstOrFail()` (same visibility rule as the public event page; miss → 404).
    - Query ready, event-scoped photos in stable order and paginate at 24: `$photos = $event->photos()->where('status', Photo::STATUS_READY)->orderByDesc('id')->paginate(24, ['id','event_id','uuid','original_path','original_filename','width','height','mime_type'])`.
    - Map items with `$photos->through(fn (Photo $p) => ['uuid'=>$p->uuid,'url'=>Storage::disk('public')->url($p->original_path),'filename'=>$p->original_filename,'width'=>$p->width,'height'=>$p->height,'mime_type'=>$p->mime_type])` so `id`, `event_id`, and `original_path` never reach the client.
    - Return `Inertia::render('Public/Gallery', ['event'=>['slug'=>$event->slug,'name'=>$event->name], 'photos'=>$photos->items(), 'pagination'=>['current_page'=>$photos->currentPage(),'last_page'=>$photos->lastPage()]])`.
    - _Requirements: 1.2, 1.6, 2.1, 2.2, 2.3, 2.4, 2.5, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.7_

  - [ ] 1.2 Create `app/Http/Controllers/PublicPhotoDownloadController.php`
    - Add `show(string $slug, string $photo): \Symfony\Component\HttpFoundation\StreamedResponse`.
    - Resolve the active event as in 1.1 (miss → 404).
    - Resolve the photo in a single event-scoped query enforcing uuid + ownership + ready: `$photo = $event->photos()->where('uuid', $photo)->where('status', Photo::STATUS_READY)->firstOrFail()` (unknown uuid, numeric id, other-event uuid, or non-ready → 404).
    - Guard missing file with a safe 404: `abort_unless(Storage::disk('public')->exists($photo->original_path), 404)` (no path/credential/stack leak).
    - Stream as forced download: `return Storage::disk('public')->download($photo->original_path, $photo->original_filename)`.
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 8.2, 8.3_

  - [ ] 1.3 Register both public routes in `routes/web.php`
    - Place both routes OUTSIDE the auth/verified group, after the existing public event routes.
    - `GET /e/{slug}/gallery` → `[PublicGalleryController::class, 'show']` named `public.events.gallery`.
    - `GET /e/{slug}/photos/{photo}/download` → `[PublicPhotoDownloadController::class, 'show']` named `public.events.photos.download` (plain `{photo}` string segment; no implicit route-model binding).
    - Add `use App\Http\Controllers\PublicGalleryController;` and `use App\Http\Controllers\PublicPhotoDownloadController;`.
    - _Requirements: 1.1, 7.1_

- [ ] 2. Public event page ready-photo count
  - [ ] 2.1 Add `photoCount` to `PublicEventController@show`
    - Add `'photoCount' => $event->photos()->where('status', Photo::STATUS_READY)->count()` to the existing Inertia payload.
    - Leave `slug`, `name`, `description`, `event_date`, `location`, `upload_enabled` and all other behavior unchanged.
    - _Requirements: 10.2, 10.3, 10.4_

- [ ] 3. TypeScript types
  - [ ] 3.1 Extend `resources/js/types/models.ts`
    - Add `GalleryPhoto { uuid: string; url: string; filename: string; width: number | null; height: number | null; mime_type: string }`.
    - Add `GalleryPagination { current_page: number; last_page: number }`.
    - Add `GalleryPageProps { event: { slug: string; name: string }; photos: GalleryPhoto[]; pagination: GalleryPagination }`.
    - Add `photoCount: number` to the existing `PublicEvent` interface.
    - _Requirements: 3.1, 4.3, 10.2_

- [ ] 4. Frontend gallery components and page
  - [ ] 4.1 Create `resources/js/components/PhotoCard.tsx`
    - Props `{ photo: GalleryPhoto; onClick: () => void }`.
    - Render a `type="button"` with an `aspect-square` container; inside, an `<img>` with `src={photo.url}`, `alt={photo.filename}`, `width`/`height` from the photo (intrinsic sizing to reduce layout shift), `loading="lazy"`, and `object-cover`.
    - Track a per-card `broken` state; on `onError`, swap the image for an `ImageOff` icon + "Photo unavailable" text without affecting sibling cards.
    - _Requirements: 5.3, 5.4, 8.1_

  - [ ] 4.2 Create `resources/js/components/PhotoGrid.tsx`
    - Props `{ photos: GalleryPhoto[]; onSelect: (index: number) => void }`.
    - Render `grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4`, mapping `PhotoCard` keyed by `photo.uuid`, wiring `onClick` to `onSelect(index)`.
    - _Requirements: 5.2_

  - [ ] 4.3 Create `resources/js/components/PhotoViewer.tsx`
    - Props `{ photos: GalleryPhoto[]; index: number; slug: string; onIndexChange: (i: number) => void; onClose: () => void }`.
    - Full-screen overlay `fixed inset-0 z-50 ... bg-black/90`; enlarged `<img>` with `object-contain`, `max-h-[85vh] max-w-[90vw]`.
    - Close via `X` button and `Escape`; prev/next via `ChevronLeft`/`ChevronRight` buttons and `ArrowLeft`/`ArrowRight` keys, wrapping via modulo over `photos.length`.
    - `useEffect` registers/removes a `keydown` listener (deps on `index`).
    - Download via a plain `<a href={`/e/${slug}/photos/${photo.uuid}/download`}>` with a `Download` icon (attachment response keeps the gallery mounted).
    - Use `lucide-react` icons `X`, `ChevronLeft`, `ChevronRight`, `Download`.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9_

  - [ ] 4.4 Create `resources/js/pages/Public/Gallery.tsx`
    - Props `GalleryPageProps`; standalone Public/ layout (no `.layout`).
    - Seed state: `items = photos`, `currentPage`/`lastPage` from `pagination`, plus `loading` and `viewerIndex` state; `hasMore = currentPage < lastPage`.
    - `<Head>` title; header shows `event.name` and a "Shared Photo Gallery" subheading.
    - Empty state when `items.length === 0`: show "No photos yet", "Be the first to share a moment from this event.", and an "Upload Photos" `Button asChild` `Link` to `/e/${event.slug}` (no second uploader).
    - Otherwise render `PhotoGrid` and, when `hasMore`, a "Load More" `Button`.
    - Load More: `router.get(`/e/${event.slug}/gallery`, { page: currentPage + 1 }, { only: ['photos','pagination'], preserveState: true, preserveScroll: true, onSuccess: (page) => append `page.props.photos` deduped by `uuid` and set `currentPage`/`lastPage` from `page.props.pagination`, onFinish: clear loading })`.
    - Render `PhotoViewer` when `viewerIndex !== null`, wiring `onIndexChange` and `onClose`.
    - _Requirements: 4.4, 4.5, 4.6, 5.1, 5.5, 6.1, 9.1, 9.2, 9.3, 9.4_

- [ ] 5. Public event page gallery link
  - [ ] 5.1 Wire the "View Gallery" button in `resources/js/pages/Public/Event.tsx`
    - Replace the placeholder "View Gallery" button with `<Button asChild size="lg" variant="outline" className="w-full"><Link href={`/e/${event.slug}/gallery`}>View Gallery{event.photoCount > 0 ? ` — ${event.photoCount} photos` : ''}</Link></Button>`.
    - Import `Link` from `@inertiajs/react`. Keep the uploader and all other content unchanged.
    - _Requirements: 10.1, 10.2, 10.4_

- [ ] 6. Feature tests
  - [ ]* 6.1 Create `tests/Feature/PhotoGalleryTest.php`
    - PHPUnit `#[Test]`, `RefreshDatabase`, `AssertableInertia`, `Storage::fake('public')`, `Event::factory()`/`Photo::factory()`.
    - Visibility (**Property 1**): active gallery → 200 with no auth (component `Public/Gallery`); nonexistent slug → 404; draft → 404; archived → 404; soft-deleted → 404.
    - Scoping (**Property 2**): with ready + pending + processing + failed + deleted + another event's ready, assert `photos` contains only the target event's ready uuids.
    - Payload minimization (**Property 3**): each item has `uuid`/`url`/`filename`/`width`/`height`/`mime_type`, `url` starts with `/storage`, and `id`/`event_id`/`original_path` are missing.
    - Pagination (**Property 4**): seed 30 ready photos; page 1 → 24 items; `?page=2` → 6 items; page 1 and page 2 uuids are disjoint and union to all 30.
    - Pagination metadata (**Property 5**): `pagination.last_page == 2`, page 1 `current_page == 1`, page 2 `current_page == 2`.
    - Empty state (**Property 1**): event with zero ready photos → 200 with empty `photos`.
    - _Requirements: 1.2, 1.3, 1.4, 1.5, 1.6, 2.1, 2.2, 2.3, 2.4, 2.5, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.7, 9.1_

  - [ ]* 6.2 Create `tests/Feature/PhotoDownloadTest.php`
    - PHPUnit `#[Test]`, `RefreshDatabase`, `Storage::fake('public')`.
    - Success (**Property 7, 10**): `put` a fake file at the ready photo's `original_path`; `GET` download → 200 with `Content-Disposition: attachment` containing the `original_filename`; streamed bytes equal the stored bytes.
    - 404 completeness (**Property 8**): nonexistent slug → 404; non-active (archived/draft) event → 404; unknown uuid → 404; another event's photo via this event's route → 404; non-ready photo → 404; numeric `id` in the `{photo}` slot → 404.
    - Missing file (**Property 9**): ready photo with no file on disk → 404 (no path/credential/stack leak).
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 8.2, 8.3, 11.3_

  - [ ]* 6.3 Extend `tests/Feature/PublicEventPageTest.php` with ready-photo count
    - **Property 11**: create an active event with 3 ready photos and 1 pending; assert `event.photoCount == 3` in the Inertia payload.
    - _Requirements: 10.2, 10.3_

- [ ] 7. Verification checkpoint
  - Run `php artisan test --filter=PhotoGalleryTest` and `php artisan test --filter=PhotoDownloadTest`, then the full `php artisan test` suite (confirm Phase 1–5 remain green).
  - Run `npx tsc --noEmit` to confirm the TypeScript types and components compile.
  - Run `php artisan route:list` and confirm `public.events.gallery` and `public.events.photos.download` are registered outside the auth group.
  - Confirm `php artisan storage:link` is in place so `/storage/...` URLs resolve.
  - Fix any failures before considering the feature complete. Ensure all tests pass, ask the user if questions arise.
  - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5_

## Notes

- Tasks marked with `*` are optional (test sub-tasks) and can be skipped for a faster MVP; core implementation tasks are never optional.
- The model MUST implement sub-tasks NOT prefixed with `*` and MUST NOT implement sub-tasks postfixed with `*`.
- Each task references specific requirement sub-clauses for traceability; test sub-tasks additionally reference the design's Correctness Properties (P1–P11).
- Client-side behavior (lightbox open/close/keys, lazy attribute, Load More append/dedupe UI, responsive grid, `onError` placeholder) is structural/manual — no backend tests cover it.
- No database migration, no new packages, no model changes. Filtering is enforced server-side only.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "2.1", "3.1"] },
    { "id": 1, "tasks": ["1.3", "4.1", "4.3"] },
    { "id": 2, "tasks": ["4.2", "5.1"] },
    { "id": 3, "tasks": ["4.4"] },
    { "id": 4, "tasks": ["6.1", "6.2", "6.3"] }
  ]
}
```
