# Implementation Plan: Queue Background Processing (Phase 8)

## Overview

Convert synchronous guest photo processing into per-photo Laravel queued jobs on the already-configured `database` queue. No new migration, package, or queue config change is needed. The work is: add the `ProcessPhoto` job, revise the upload controller to dispatch instead of process, update the frontend success copy, update the affected Phase 7 tests to the async expectation, and add new queue/job tests. Tasks build incrementally — the job first, then the controller that dispatches it, then the copy and the tests that exercise both.

## Tasks

- [x] 1. Create the ProcessPhoto queued job
  - [x] 1.1 Implement `app/Jobs/ProcessPhoto.php`
    - Create `class ProcessPhoto implements ShouldQueue` using `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`.
    - Imports: `Illuminate\Bus\Queueable`, `Illuminate\Contracts\Queue\ShouldQueue`, `Illuminate\Foundation\Bus\Dispatchable`, `Illuminate\Queue\InteractsWithQueue`, `Illuminate\Queue\SerializesModels`, `Illuminate\Support\Facades\Log`, `Illuminate\Support\Facades\Storage`, `App\Models\Photo`, `App\Services\PhotoProcessor`, `Throwable`.
    - `public int $tries = 3;` and constructor `public function __construct(public int $photoId) {}`.
    - `backoff(): array { return [10, 30, 60]; }`.
    - `handle(PhotoProcessor $processor): void`: `Photo::find($this->photoId)` → return if null (6.1); return if `status === STATUS_READY` with both `optimized_path` and `thumbnail_path` set (6.2); if original missing on `Storage::disk('public')` → set `STATUS_FAILED` and return (6.3); set `STATUS_PROCESSING` (3.1); call `$processor->process($photo->original_path, $photo->event->uuid, $photo->uuid)` (3.2, 3.5); store `optimized_path`/`thumbnail_path` and set `STATUS_READY` (3.3, 3.4, 6.4, 6.5).
    - `failed(?Throwable $exception): void`: `Photo::find` → return if null; if `$photo->event` present delete the optimized + thumbnail WebP paths (4.3); set `STATUS_FAILED` (4.2); `Log::error('Photo processing failed', ['photo_id' => $this->photoId, 'exception' => $exception?->getMessage()])` (4.4).
    - _Requirements: 2.3, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 6.1, 6.2, 6.3, 6.4, 6.5_

- [x] 2. Dispatch jobs from the upload controller
  - [x] 2.1 Revise `app/Http/Controllers/PublicPhotoUploadController@store`
    - Remove the `PhotoProcessor` parameter/injection, the try/catch processing block, and the `$failures` counter; add `use App\Jobs\ProcessPhoto;` and remove any now-unused imports.
    - Keep event resolution (`firstOrFail` → 404, 1.5), `abort_if(! $event->upload_enabled, 403, ...)` (1.6), validation, and original storage (1.1).
    - Per file: store the original, run `getimagesize`, `Photo::create([... 'status' => Photo::STATUS_PENDING])` from server-determined values (1.2, 1.7), then `ProcessPhoto::dispatch($photo->id)` (2.1, 2.2).
    - After the loop, `return back()->with('success', 'Your photos have been uploaded and are being processed.')` with no synchronous variant generation (1.3, 1.4).
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 2.1, 2.2_

- [x] 3. Update the guest upload success copy
  - [x] 3.1 Update `resources/js/components/PhotoUploader.tsx`
    - Change the success `<p>` text from "Your photos have been added to the event!" to "Your photos have been uploaded and are being processed. They may take a moment to appear."
    - No logic, polling, WebSocket, or broadcasting changes.
    - _Requirements: 8.1, 8.2_

- [x] 4. Update Phase 7 regression tests to the async expectation
  - [x] 4.1 Update `tests/Feature/GuestPhotoUploadTest.php`
    - Add `use Illuminate\Support\Facades\Queue;` and `use App\Jobs\ProcessPhoto;`.
    - `upload_to_active_enabled_event_succeeds_and_persists_metadata`: call `Queue::fake()` at the start, change the status assertion from `STATUS_READY` to `STATUS_PENDING`, add `Queue::assertPushed(ProcessPhoto::class)`; keep event/path/filename/uuid/dimensions/original-exists assertions.
    - `client_cannot_override_event_id_status_or_path`: change expected status `STATUS_READY` → `STATUS_PENDING`; keep event_id and path assertions.
    - For 404/403/validation/throttle/format tests, add `Queue::fake()` only where a valid upload currently relies on synchronous processing side effects; otherwise leave unchanged. Do not rewrite unrelated assertions.
    - _Requirements: 10.9, 10.10_
  - [x] 4.2 Update `tests/Feature/ImageProcessingTest.php`
    - Do NOT `Queue::fake()` here — processing must run for real. After each `$this->upload(...)`, fetch the created pending photo and run `\App\Jobs\ProcessPhoto::dispatchSync($photo->id);` then `$photo->refresh();` before the ready/variant/dimension/orientation assertions.
    - Insert the `dispatchSync($photo->id)` step for: `upload_stores_original_optimized_thumbnail_and_marks_ready`, `large_image_is_reduced`, `small_image_is_not_upscaled`, `portrait_image_stays_portrait`, `supported_formats_process`, `original_bytes_unchanged_after_processing`, and `adversarial_filename_uses_uuid_paths`.
    - `processing_failure_marks_failed_and_cleans_up`: keep the throwing `PhotoProcessor` container binding, upload (photo pending), run the job via `dispatchSync` (or call `handle()` and catch the surfaced exception), then invoke the job's `failed()` to simulate retry exhaustion → assert status `failed`, partial files removed, original kept.
    - Leave `invalid_file_is_rejected` and `more_than_twenty_files_rejected` unchanged (validation rejects before any photo/job).
    - _Requirements: 10.10_

- [x] 5. Add new queue/job tests
  - [x] 5.1 Create `tests/Feature/QueuedPhotoProcessingTest.php`
    - PHPUnit `#[Test]`, `RefreshDatabase`, `Storage::fake('public')`, `Queue`, `Event`/`Photo` factories, and a GD image-synthesis helper (as in the Phase 7 tests).
    - `upload_dispatches_one_job_per_photo_and_leaves_pending` — **Property 1, 2**: `Queue::fake()`; upload 3 GD images; assert `Photo::count() === 3`, each `STATUS_PENDING` with null `optimized_path`/`thumbnail_path`, each original exists on disk; `Queue::assertPushed(ProcessPhoto::class, 3)`.
    - `upload_does_not_process_synchronously` — **Property 1**: `Queue::fake()`; upload 1; photo `PENDING`, `optimized_path` null, no optimized/thumbnail files on disk.
    - `job_processes_pending_photo_to_ready` — **Property 3**: create event + pending photo with a real GD original at `events/{event->uuid}/originals/{photo->uuid}.jpg`; `ProcessPhoto::dispatchSync($photo->id)`; refresh; status `READY`, both paths set, both files exist and are `image/webp`.
    - `job_marks_failed_and_cleans_up_on_processor_error` — **Property 4, 5**: bind a throwing `PhotoProcessor`; pre-place partial optimized + thumbnail files; `$job = new ProcessPhoto($photo->id); $job->failed(new \RuntimeException('boom'));` assert status `FAILED`, both partial files deleted, original still exists.
    - `job_defines_finite_retry_config` — **Requirements 5.1, 5.2**: `$job = new ProcessPhoto(1);` assert `$job->tries === 3` and `$job->backoff() === [10, 30, 60]`.
    - `job_is_noop_for_missing_photo` — **Property 7**: `ProcessPhoto::dispatchSync(999999)` → no exception; no files on disk.
    - `job_skips_already_ready_photo` — **Property 8**: photo `READY` with both paths set; bind a throwing `PhotoProcessor` that would fail if called; `dispatchSync`; assert status still `READY` and processor not invoked (no throw).
    - `job_marks_failed_when_original_missing` — **Property 9**: pending photo, no original on disk; `dispatchSync`; refresh; status `FAILED`, no exception, no variant files.
    - `reprocessing_is_idempotent_no_duplicate_rows` — **Property 6**: pending photo with real original; `dispatchSync` twice; assert status `READY`, `Photo::count()` unchanged (1), paths stable.
    - `multiple_photos_independent_one_failure` — **Property 11**: 3 pending photos, middle one's original missing; run all 3 jobs; assert middle `FAILED`, other two `READY`.
    - `gallery_shows_only_ready` — **Property 10**: event with 1 ready + 1 pending + 1 processing + 1 failed; `GET /e/{slug}/gallery`; `AssertableInertia` `has('photos', 1)`.
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7, 10.8_

- [x] 6. Verification checkpoint
  - Run `php artisan test --filter=QueuedPhotoProcessingTest`, then `--filter=GuestPhotoUploadTest`, then `--filter=ImageProcessingTest`, then the full `php artisan test` suite (all Phase 1–7 green under the updated async expectations).
  - Run `npx tsc --noEmit` to confirm the frontend copy change type-checks.
  - Confirm `php artisan queue:work` starts in dev. Document the worker commands (`php artisan queue:work`, `php artisan queue:failed`, `php artisan queue:retry all`) and worker-stopped behavior (jobs stay enqueued; photos remain pending until a worker runs).
  - Manual check: with a worker running, upload → photo appears in the gallery after the job runs; without a worker, the photo stays out of the gallery (pending) until a worker runs.
  - Ensure all tests pass, ask the user if questions arise.
  - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 10.10_

## Notes

- All test tasks in this plan are required (not optional) — Phase 8's core deliverable includes the async-behavior test coverage, so no sub-task is postfixed with `*`.
- Each task references specific requirement sub-clauses for traceability; test sub-tasks are annotated with the design property numbers they validate.
- `ProcessPhoto` reuses `App\Services\PhotoProcessor` verbatim — no image-processing logic is duplicated.
- No new migration, package, or queue configuration change: the `jobs`/`failed_jobs` tables and `database` queue connection already exist.
- Property tests should randomize over file count, image dimensions/format, and photo state; tag each with `Feature: queue-background-processing, Property {n}: {property text}`.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "3.1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["4.1", "4.2", "5.1"] }
  ]
}
```
