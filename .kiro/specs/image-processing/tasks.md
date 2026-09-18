# Implementation Plan: Image Processing (MomentGather Phase 7)

## Overview

This plan turns the Phase 7 design into incremental coding steps. It installs a single image library, adds two nullable columns to the `photos` table, teaches the `Photo` model to expose variant URLs with legacy fallback, adds a `PhotoProcessor` service, wires synchronous processing into the guest upload flow, updates the gallery payload and frontend to use thumbnail/optimized URLs, adds an idempotent `photos:process` backfill command, and covers everything with feature tests plus a final verification checkpoint.

Language is PHP (Laravel 13 / PHP 8.3) for the backend and TypeScript/React for the frontend, as specified throughout the design — no language selection question is needed.

Each task builds on the previous ones and ends by wiring code into the running upload and gallery paths. `gd`, `exif`, and GD WebP are already enabled and verified locally.

## Tasks

- [ ] 1. Install library, add schema, and update the Photo model
  - [ ] 1.1 Install the image library
    - Run `composer require intervention/image:^3` and confirm it installs cleanly on Laravel 13 / PHP 8.3 with the GD driver
    - Verify no other image library is added (exactly one image package total); no service provider or config publish required
    - _Requirements: 1.1, 1.2, 1.3_

  - [ ] 1.2 Add nullable processed-path columns via a new migration
    - Create `database/migrations/{timestamp}_add_processed_paths_to_photos_table.php`
    - In `up()`, `Schema::table('photos')` add `string('optimized_path', 512)->nullable()->after('original_path')` and `string('thumbnail_path', 512)->nullable()->after('optimized_path')`
    - In `down()`, `dropColumn(['optimized_path', 'thumbnail_path'])`
    - Do NOT edit the existing `photos` migration
    - _Requirements: 9.1, 9.2, 9.3_

  - [ ] 1.3 Update the Photo model with fillable columns and URL accessors
    - Add `optimized_path` and `thumbnail_path` to the `#[Fillable(...)]` attributes
    - Add `use Illuminate\Support\Facades\Storage;`
    - Add `originalUrl(): string` (public disk URL of `original_path`), `optimizedUrl(): string` (URL of `optimized_path ?? original_path`), and `thumbnailUrl(): string` (URL of `thumbnail_path ?? original_path`)
    - Add `@property string|null $optimized_path` and `@property string|null $thumbnail_path` to the docblock
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 12.1, 12.2_

- [ ] 2. Implement the PhotoProcessor service
  - [ ] 2.1 Create `app/Services/PhotoProcessor.php`
    - Add `process(string $originalPath, string $eventUuid, string $photoUuid): array` returning `['optimized_path' => ..., 'thumbnail_path' => ...]`
    - Instantiate `new ImageManager(new Intervention\Image\Drivers\Gd\Driver())`; use `Storage::disk('public')`; read source bytes once with `$disk->get($originalPath)`
    - Optimized: `read($contents)->scaleDown(OPTIMIZED_MAX, OPTIMIZED_MAX)` then `put` as WebP quality 82 at `events/{eventUuid}/optimized/{photoUuid}.webp`; release the instance
    - Thumbnail: fresh `read($contents)->scaleDown(THUMBNAIL_MAX, THUMBNAIL_MAX)` then `put` as WebP quality 82 at `events/{eventUuid}/thumbnails/{photoUuid}.webp`; release the instance
    - Define constants `OPTIMIZED_MAX = 2048`, `THUMBNAIL_MAX = 500`, `WEBP_QUALITY = 82`; let decode/encode/storage errors throw (`\Throwable`) for the caller to handle
    - _Requirements: 1.1, 1.2, 4.1, 4.2, 4.3, 4.4, 4.5, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 8.1, 13.1, 13.2, 19.1_

- [ ] 3. Integrate synchronous processing into the upload controller
  - [ ] 3.1 Revise `PublicPhotoUploadController@store`
    - Add a `PhotoProcessor $processor` parameter to the `store` signature
    - Per file: store the original unchanged (Phase 5 behavior), read dimensions via `getimagesize`, and `Photo::create` with `status = Photo::STATUS_PROCESSING`
    - On success: `$photo->update([...paths, 'status' => Photo::STATUS_READY])`
    - On `\Throwable`: delete `events/{uuid}/optimized/{uuid}.webp` and `events/{uuid}/thumbnails/{uuid}.webp`, set `status = Photo::STATUS_FAILED`, increment a `$failures` counter, keep the original, and continue the loop
    - After the loop: if `$failures > 0` return `back()->with('success', friendly partial-failure note)`, else `back()->with('success', 'Your photos have been added to the event!')`; never leak exception details
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 14.1, 14.2, 14.3, 15.1, 15.2, 18.1, 18.2, 18.3, 18.4, 19.1_

- [ ] 4. Update the gallery payload and frontend
  - [ ] 4.1 Revise `PublicGalleryController@show` payload
    - Select `id, event_id, uuid, original_path, optimized_path, thumbnail_path, original_filename, width, height` in the ready-only `paginate()`
    - Map each photo to `['uuid' => ..., 'thumbnailUrl' => $photo->thumbnailUrl(), 'optimizedUrl' => $photo->optimizedUrl(), 'filename' => $photo->original_filename, 'width' => ..., 'height' => ...]`
    - Keep the ready-only filter so failed/processing photos are excluded
    - _Requirements: 11.1, 11.2, 11.3, 11.5, 11.6, 12.1, 12.2, 14.4_

  - [ ] 4.2 Update the `GalleryPhoto` TypeScript type
    - In `resources/js/types/models.ts`, replace `url` and `mime_type` with `thumbnailUrl: string` and `optimizedUrl: string`; keep `uuid`, `filename`, `width`, `height`
    - _Requirements: 20.1_

  - [ ] 4.3 Update PhotoCard to use the thumbnail URL
    - In `resources/js/components/PhotoCard.tsx`, set the grid `<img src={photo.thumbnailUrl}>`
    - _Requirements: 20.2, 20.4_

  - [ ] 4.4 Update PhotoViewer to use the optimized URL
    - In `resources/js/components/PhotoViewer.tsx`, set the viewer `<img src={photo.optimizedUrl}>`; leave the download link unchanged (still serves the original)
    - _Requirements: 20.3, 20.4_

- [ ] 5. Add the backfill command
  - [ ] 5.1 Create `app/Console/Commands/ProcessPhotos.php` (`photos:process`)
    - `chunkById` ready photos where `optimized_path` or `thumbnail_path` is null, eager loading `event:id,uuid`
    - Skip and warn when `!$disk->exists($photo->original_path)`; otherwise call `$processor->process(...)` and update the two paths
    - Print an `info` summary of processed/skipped counts; be idempotent (already-processed photos do not match the query) and never delete records
    - _Requirements: 15.3, 16.1, 16.2, 16.3, 16.4, 16.5_

- [ ] 6. Feature tests
  - [ ] 6.1 Create `tests/Feature/ImageProcessingTest.php`
    - Use PHPUnit `#[Test]`, `RefreshDatabase`, `Storage::fake('public')`; include the `makeImage(w, h, format)` GD synthesis helper and `storedDimensions(path)` from the design
    - Upload success → original + optimized + thumbnail stored at expected UUID paths, `optimized_path`/`thumbnail_path` saved, status `ready` — **Property 5, Property 8** (Req 2.2, 3.1, 4.5, 5.5)
    - Large 3000×2000 → optimized max dim ≤ 2048, thumbnail max dim ≤ 500, both WebP, aspect preserved — **Property 1, Property 2** (Req 4.1–4.4, 5.1–5.4)
    - Small 1×1 fixture → optimized and thumbnail stay 1×1 (no upscale) — **Property 1, Property 2** (Req 4.2, 5.2)
    - Portrait 1000×1500 → optimized and thumbnail have height > width — **Property 4** (Req 6.1, 6.2)
    - jpg/png/webp each process successfully — (Req 7.1, 8.1)
    - Original bytes hash unchanged after processing; format unchanged — **Property 3** (Req 3.2, 3.3, 6.3, 8.2, 15.1)
    - Invalid `not-an-image.jpg` / more than 20 files / oversized rejected by validation, no photo row, no files — (Req 7.1, 7.2, 17.1, 17.2, 17.3, 18.1, 18.2, 18.3)
    - Adversarial client filename → processed paths use the photo UUID only — **Property 5** (Req 13.3, 18.4)
    - Processing failure via a container-bound fake `PhotoProcessor` that throws → status `failed`, no processed files, original kept, friendly redirect with no exception text — **Property 8** (Req 14.1, 14.2, 14.3, 15.2)
    - _Requirements: 2.2, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3, 4.4, 4.5, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3, 7.1, 7.2, 8.1, 8.2, 13.3, 14.1, 14.2, 14.3, 15.1, 15.2, 17.1, 17.2, 17.3, 18.1, 18.2, 18.3, 18.4_

  - [ ] 6.2 Create `tests/Feature/GalleryPayloadTest.php`
    - Use `AssertableInertia` against component `Public/Gallery`
    - Ready photos → each payload entry has `thumbnailUrl` and `optimizedUrl`; processing/failed photos excluded — **Property 7** (Req 11.1, 11.6, 14.4, 20.1)
    - Legacy photo (null processed paths) → `thumbnailUrl` and `optimizedUrl` both equal the original URL — **Property 6** (Req 9.3, 10.5, 10.6, 11.5, 20.4)
    - Photo with processed paths → accessors return the processed URLs — **Property 6** (Req 10.3, 10.4)
    - Download endpoint still streams the original — (Req 11.4, 15.1)
    - _Requirements: 9.3, 10.3, 10.4, 10.5, 10.6, 11.1, 11.4, 11.5, 11.6, 14.4, 15.1, 20.1, 20.4_

  - [ ] 6.3 Create `tests/Feature/ProcessPhotosCommandTest.php`
    - Legacy ready photo with original present and null processed paths → `photos:process` generates both variants and updates the DB — **Property 9** (Req 16.1)
    - Already-processed photo → skipped (no change); a second run is a no-op (idempotent) — **Property 9** (Req 16.2, 16.4)
    - Missing original → skipped, record intact, not deleted; record count and originals unchanged after run — **Property 9** (Req 16.3, 16.5, 15.3)
    - _Requirements: 15.3, 16.1, 16.2, 16.3, 16.4, 16.5_

- [ ] 7. Verification checkpoint
  - Run `php artisan migrate` to add the columns
  - Run `php artisan test --filter=ImageProcessingTest`, `--filter=GalleryPayloadTest`, `--filter=ProcessPhotosCommandTest`, then the full `php artisan test` suite (Phases 1–6 must stay green)
  - Run `npx tsc --noEmit` to confirm the frontend type change compiles
  - Optionally run `php artisan photos:process` against dev data; document the `gd`/`exif`/WebP and `memory_limit` considerations
  - Manual check: upload a large phone photo and a portrait photo, verify the gallery grid loads thumbnails, the viewer shows the optimized image, download gives the original, and orientation is correct
  - Fix any failures, then ask the user if questions arise
  - _Requirements: 21.1, 21.2, 21.3_

## Notes

- Frontend visual behavior (grid uses the thumbnail, viewer uses the optimized image) is asserted at the payload level (task 6.2) and via the `GalleryPhoto` type (task 4.2); the actual `<img src>` wiring is structural and confirmed in the manual step of the checkpoint.
- Orientation testing is pragmatic: a portrait image staying portrait (height > width) after processing is asserted via dimensions rather than exact EXIF byte inspection.
- The tests in section 6 are the core safety net for this phase and are intentionally NOT marked optional — implement them as part of the plan.
- No property-based testing library is introduced; the design's correctness properties are realized as example-based and generated-input PHPUnit tests, each tagged with **Feature: image-processing, Property N: {property text}**.
- Each task references specific requirement sub-clauses for traceability; checkpoints ensure incremental validation.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "4.2"] },
    { "id": 1, "tasks": ["1.3", "2.1"] },
    { "id": 2, "tasks": ["3.1", "4.1", "4.3", "4.4", "5.1"] },
    { "id": 3, "tasks": ["6.1", "6.2", "6.3"] }
  ]
}
```
