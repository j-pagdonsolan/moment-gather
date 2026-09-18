# Implementation Plan: Guest Photo Upload (MomentGather Phase 5)

## Overview

This plan implements unauthenticated guest photo upload for an active, upload-enabled event, following the design document exactly. Work proceeds from the backend foundation (migration, model, relationship, config) outward to the rate limiter, validation, controller, public route, and finally the frontend uploader, closing with fixture-based feature tests and a verification checkpoint.

Environment facts that shape implementation and tests:

- `getimagesize()` and `fileinfo` are available; runtime dimension/MIME extraction works.
- `gd` is NOT available, so `UploadedFile::fake()->image()` MUST NOT be used. Tests load committed real image fixtures via `new UploadedFile($path, $name, $mime, null, true)`.
- No new packages. All artifacts use Laravel, Inertia v3, React 19, and existing shadcn/ui components.

All trust-sensitive values (event association, storage path/filename, MIME type, status) are server-determined; client-supplied values for these are ignored.

## Tasks

- [ ] 1. Database and model foundation
  - [ ] 1.1 Create the `photos` migration
    - Create `database/migrations/..._create_photos_table.php` following the `events` migration conventions.
    - Columns: `id`; `foreignId('event_id')->constrained()->cascadeOnDelete()->index()`; `char('uuid', 36)->unique()`; `string('original_filename', 255)`; `string('original_path', 512)`; `string('mime_type', 100)`; `unsignedBigInteger('file_size')`; `unsignedInteger('width')->nullable()`; `unsignedInteger('height')->nullable()`; `string('status', 20)->default('ready')->index()`; `timestamps()`; `softDeletes()`.
    - `down()` drops the `photos` table via `Schema::dropIfExists('photos')`.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [ ] 1.2 Create the `Photo` model
    - Create `app/Models/Photo.php` using the `#[Fillable([...])]` attribute style consistent with `Event`/`User`.
    - Fillable: `event_id`, `uuid`, `original_filename`, `original_path`, `mime_type`, `file_size`, `width`, `height`, `status`.
    - Use `HasFactory` and `SoftDeletes`; add casts (`file_size`, `width`, `height` → integer; `deleted_at` → datetime).
    - Define status constants `STATUS_PENDING`, `STATUS_PROCESSING`, `STATUS_READY`, `STATUS_FAILED`, `STATUS_DELETED`.
    - Add `booted()` hook that generates a `uuid` on `creating` when empty; add `event(): BelongsTo`.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 4.3_

  - [ ] 1.3 Add `photos()` relationship to `Event`
    - Add `use Illuminate\Database\Eloquent\Relations\HasMany;` and a `photos(): HasMany` method returning `$this->hasMany(Photo::class)` to `app/Models/Event.php`.
    - _Requirements: 3.6, 18.2_

  - [ ] 1.4 Create `PhotoFactory`
    - Create `database/factories/PhotoFactory.php` with defaults reflecting a ready upload; `event_id => Event::factory()`, generated `uuid`, `status = Photo::STATUS_READY`, sensible `original_filename`/`original_path`/`mime_type`/`file_size`/`width`/`height`.
    - _Requirements: 19.5 (test support)_

- [ ] 2. Upload configuration and rate limiter
  - [ ] 2.1 Create `config/uploads.php`
    - Create `config/uploads.php` returning `rate_limit` (env `UPLOAD_RATE_LIMIT`, default 10), `max_files` (env `UPLOAD_MAX_FILES`, default 20), `max_file_kb` (env `UPLOAD_MAX_FILE_KB`, default 20480).
    - _Requirements: 16.3_

  - [ ] 2.2 Register the `uploads` named rate limiter
    - In `app/Providers/AppServiceProvider.php` `boot()`, after the existing `$this->configureDefaults();` call (do NOT remove it), register `RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute((int) config('uploads.rate_limit', 10))->by($request->ip()));`.
    - Add imports for `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, and `Illuminate\Support\Facades\RateLimiter`.
    - _Requirements: 16.1, 16.2_

- [ ] 3. Validation and upload controller
  - [ ] 3.1 Create `StorePhotosRequest`
    - Create `app/Http/Requests/StorePhotosRequest.php` following the `StoreEventRequest` convention: `authorize()` returns `true`.
    - Rules: `photos` → `required|array|max:` config `max_files`; `photos.*` → `required|file|image|mimes:jpg,jpeg,png,webp|max:` config `max_file_kb`.
    - Add `messages()` with friendly text containing no paths, stack traces, or DB details.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 15.1, 15.4_

  - [ ] 3.2 Create `PublicPhotoUploadController`
    - Create `app/Http/Controllers/PublicPhotoUploadController.php` with `store(StorePhotosRequest $request, string $slug): RedirectResponse`.
    - Resolve the event active-only: `Event::query()->where('slug', $slug)->where('status', 'active')->firstOrFail()` (404 on no match; SoftDeletes auto-excludes deleted).
    - `abort_if(! $event->upload_enabled, 403, 'Photo uploads are currently closed.')`.
    - For each uploaded file: generate `uuid`, derive `ext`, build path `events/{event_uuid}/originals/{uuid}.{ext}`, `Storage::disk('public')->putFileAs(...)`, extract width/height/mime via `getimagesize()` on the stored file (fall back to `getMimeType()`), then `DB::transaction(fn () => Photo::create([...]))` with server-authoritative values (`event_id` from resolved event, `original_filename` = client name for display, `original_path` = server path, `mime_type` = server-detected, `file_size`, `status` = `Photo::STATUS_READY`).
    - On `catch (\Throwable)`: delete the stored file (no orphan) and `back()->withErrors([...])->withInput()` with a friendly message; on success `back()->with('success', 'Your photos have been added to the event!')`.
    - Ignore any client-supplied `event_id`, `status`, or path.
    - _Requirements: 4.1, 4.2, 5.2, 5.3, 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 8.4, 9.1, 9.2, 9.3, 9.4, 10.1, 10.2, 10.3, 15.4, 17.1, 17.2, 17.3, 17.4, 18.1_

- [ ] 4. Public upload route
  - [ ] 4.1 Register `POST /e/{slug}/photos`
    - In `routes/web.php`, add `use App\Http\Controllers\PublicPhotoUploadController;` and register `Route::post('/e/{slug}/photos', [PublicPhotoUploadController::class, 'store'])->middleware('throttle:uploads')->name('public.events.photos.store');` OUTSIDE the auth group, immediately after the existing public `show` route. Leave all other routes unchanged.
    - _Requirements: 1.2, 5.1_

- [ ] 5. Frontend uploader
  - [ ] 5.1 Expose `slug` in the public event payload and type
    - Edit `app/Http/Controllers/PublicEventController.php` `show()` payload to add `'slug' => $event->slug` (leave all other fields as-is).
    - Edit `resources/js/types/models.ts` `PublicEvent` interface to add `slug: string`.
    - _Requirements: 5.2 (uploader support)_

  - [ ] 5.2 Create `PhotoUploader.tsx`
    - Create `resources/js/components/PhotoUploader.tsx` with props `{ slug: string }` using Inertia `useForm<{ photos: File[] }>`.
    - Hidden file input (`accept="image/jpeg,image/png,image/webp"`, `multiple`); "Select Photos" button (from `@/components/ui/button`); object-URL preview thumbnail grid with per-item remove (`X` from `lucide-react`); revoke object URLs on remove, success, and unmount.
    - Heading "Share Your Moments"; instructional text "Select photos from your device and add them to this event.".
    - "Upload Photos" button shown only when files are selected; disabled while `processing`; shows `progress.percentage`.
    - `post(\`/e/${slug}/photos\`, { forceFormData: true, preserveScroll: true, onSuccess: reset + success state })`; success text "Your photos have been added to the event!"; render the `errors` bag as friendly text.
    - _Requirements: 11.1, 11.2, 11.3, 12.1, 12.2, 12.3, 12.4, 12.5, 13.1, 13.2, 13.3, 14.1, 14.2, 14.3, 15.1, 15.2, 15.3_

  - [ ] 5.3 Wire the uploader into `Public/Event.tsx`
    - Edit `resources/js/pages/Public/Event.tsx`: import `PhotoUploader`; when `event.upload_enabled`, render `<PhotoUploader slug={event.slug} />` in place of the placeholder Upload Photos button; otherwise render "Photo uploads are currently closed."; keep the View Gallery placeholder.
    - _Requirements: 11.4, 11.5_

- [ ] 6. Feature tests and fixtures
  - [ ] 6.1 Add real image fixtures
    - Add committed, tiny, real image files under `tests/Fixtures/`: `sample.jpg`, `sample.jpeg`, `sample.png`, `sample.webp`, and `not-an-image.jpg` (text bytes with a `.jpg` name for the invalid-image case).
    - These are loaded in tests via `new UploadedFile($path, $name, $mime, null, true)` because `gd` is unavailable.
    - _Requirements: 19.2 (test support)_

  - [ ]* 6.2 Write `GuestPhotoUploadTest`
    - Create `tests/Feature/GuestPhotoUploadTest.php` (PHPUnit `#[Test]`, `RefreshDatabase`, `Storage::fake('public')`, `Event::factory()`), tagging each test with `// Feature: guest-photo-upload, Property N: {property text}`.
    - **Property 1 (Unauthenticated acceptance):** guest uploads with no credentials succeed. **Validates: Requirements 1.1, 1.3, 1.4**
    - **Property 5 (Validation soundness):** jpg/jpeg/png/webp accepted; unsupported rejected (422); oversize rejected via `create('big.jpg', 21000, ...)` (422); >20 files rejected (422); invalid image (`not-an-image.jpg`) rejected (422). **Validates: Requirements 6.1–6.6**
    - **Property 3 (404):** draft, archived, soft-deleted events return 404. **Validates: Requirements 7.2**
    - **Property 4 (403):** `upload_enabled = false` returns 403. **Validates: Requirements 7.3**
    - **Properties 6, 7, 8, 11, 13:** on success assert `Storage::disk('public')->assertExists` at `events/{uuid}/originals/{photo_uuid}.ext`, `event_id` matches resolved event, `status` = `ready`, `original_filename` preserved, stored filename = `{uuid}.ext` and distinct from original, `uuid` generated. **Validates: Requirements 3.4, 3.6, 5.2, 5.3, 8.1–8.4, 9.1, 10.3, 17.1, 17.2, 18.1, 18.2, 19.5**
    - **Properties 6, 7, 8 (client override ignored):** send body `event_id`, `status='failed'`, `original_path='../../etc/evil.jpg'`; assert persisted `event_id` = target, `status` = `ready`, `original_path` = server path. **Validates: Requirements 17.1, 17.2, 17.4, 18.1, 19.6**
    - **Property 14 (Rate limiting):** issuing more than the limit within a minute returns 429. **Validates: Requirements 16.1, 16.2, 19.7**
    - Note: client-side items (previews, progress bar, remove UI) are manual/structural and are not covered by backend tests.
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7_

- [ ] 7. Verification checkpoint
  - Ensure all tests pass, ask the user if questions arise.
  - Run `php artisan test --filter=GuestPhotoUploadTest`, then the full `php artisan test`.
  - Run `npx tsc --noEmit` to confirm the TypeScript changes compile.
  - Run `php artisan route:list` to confirm `public.events.photos.store` resolves to `POST /e/{slug}/photos` with the `throttle:uploads` middleware.
  - Operational note (not automated here): `php artisan migrate` in the dev DB and `php artisan storage:link` once per environment for real web serving; tests use in-memory SQLite and `Storage::fake('public')`.
  - Fix any failures. Manually verify the uploader UI, progress, previews, and rate limiting in the browser.

## Notes

- Tasks marked with `*` are optional (test authoring) and can be skipped for a faster MVP; core implementation tasks are never optional.
- Each task references specific requirement sub-clauses for traceability.
- Test sub-tasks are annotated with the design's Correctness Property numbers (P1–P14).
- Property-based tests should run a minimum of 100 iterations where randomization adds value (arbitrary client body values for Properties 6/8, arbitrary counts/sizes/formats for Property 5), iterating over the committed fixture set for content-dependent properties (7, 10, 11) since `gd` is unavailable.
- No new packages are introduced; the original uploaded image is stored unchanged.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "2.1"] },
    { "id": 1, "tasks": ["1.3", "1.4", "2.2", "3.1"] },
    { "id": 2, "tasks": ["3.2", "5.1", "5.2"] },
    { "id": 3, "tasks": ["4.1", "5.3"] },
    { "id": 4, "tasks": ["6.1", "6.2"] }
  ]
}
```
