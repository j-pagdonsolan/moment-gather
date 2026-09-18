# Implementation Plan: Security + Rate Limiting (Phase 9)

## Overview

This plan hardens the public surface of MomentGather with **minimal, additive churn**. It is derived directly from the approved requirements.md and design.md.

Two kinds of task appear here and are labeled explicitly:

- **[NEW]** — additive code for a genuine gap (config key, one limiter, one middleware, one guard clause, one FormRequest hook, one tracked file, one frontend branch). Never change an existing method signature, route handler, model, or migration shape.
- **[VERIFY]** — tests only, no production-code refactor. These lock in already-correct Phase 1–8 behavior. **Do NOT refactor working code** for these; if a test fails, the fix is the test or a genuine regression, not a rewrite of the shipped feature.

Ordering: config/env, `.htaccess`, and the `SecurityHeaders` middleware land first (foundation, wave 0); wiring that depends on them lands next (wave 1); the new test files that exercise the code land after (wave 2); final verification runs last (wave 3). Independent files are kept in the same wave.

Language is fixed by the design: PHP 8 / Laravel 13 for backend, TypeScript / React 19 for frontend. Tests are Pest/PHPUnit feature tests under `tests/Feature`.

## Tasks

- [x] 1. Foundation: config, env, PBT dependency, static protections
  - [x] 1.1 Add browse and per-event config keys **[NEW]**
    - Edit `config/uploads.php`: add `'browse_rate_limit' => (int) env('BROWSE_RATE_LIMIT', 60)` and `'max_per_event' => (int) env('UPLOAD_MAX_PER_EVENT', 500)` alongside the existing three keys. Do not change existing keys.
    - Add `BROWSE_RATE_LIMIT=60` and `UPLOAD_MAX_PER_EVENT=500` to `.env.example` for discoverability (documentation only).
    - _Requirements: 7.5, 11.1_

  - [x] 1.2 Create the uploads-area `.htaccess` (defense-in-depth) **[NEW]**
    - Create `storage/app/public/.htaccess` with the exact directives from the design: `php_flag engine off`; a `<FilesMatch "\.(php|phtml|php3|php4|php5|php7|phar|pl|py|cgi|asp|aspx|sh|shtml)$">Require all denied</FilesMatch>` block; and `RemoveHandler .php .phtml .phar` + `RemoveType .php .phtml .phar`.
    - The directory is gitignored, so force-add the file to version control: `git add -f storage/app/public/.htaccess`. In a comment/note record the assumption that this file is version-controlled and must exist in the uploads area, and that production (Phase 14) will additionally serve user content from a non-executable location or separate domain. Do NOT add an artisan/deploy step — a tracked file is the intended choice.
    - _Requirements: 4.2, 4.3, 4.4_

  - [x] 1.3 Create the `SecurityHeaders` middleware class **[NEW]**
    - Create `app/Http/Middleware/SecurityHeaders.php` with `handle(Request $request, Closure $next): Response` that captures `$response = $next($request)`, sets `X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN`, and returns the response. No CSP. Match the exact shape in the design's Components section.
    - Do NOT register it here (registration is task 2.3) — this task only creates the class file.
    - _Requirements: 19.1, 19.2, 19.3_

  - [x] 1.4 Pin the property-based testing dev dependency **[NEW]**
    - Add a PBT library to `composer.json` require-dev and install it: prefer `innmind/black-box` (Pest-compatible), fallback `giorgiosironi/eris`. Run `composer require --dev innmind/black-box` (or the chosen lib).
    - If the reviewer prefers not to adopt a PBT lib, instead document in this task that property tests will use data-provider / bounded loop-based generation (>=100 iterations) as the fallback, and state that choice explicitly. Every property test in wave 2 must run a minimum of 100 iterations either way.
    - _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.11_

- [x] 2. Wiring: register the new controls into the request pipeline
  - [x] 2.1 Register the `browse` rate limiter **[NEW]**
    - In `app/Providers/AppServiceProvider::boot()`, add `RateLimiter::for('browse', fn (Request $request) => Limit::perMinute((int) config('uploads.browse_rate_limit', 60))->by($request->ip()))` next to the existing `uploads` limiter. Keep the existing `uploads` limiter untouched.
    - _Requirements: 7.1, 7.3, 8.1, 8.2_

  - [x] 2.2 Apply `throttle:browse` to the three browse routes **[NEW]**
    - In `routes/web.php` add `->middleware('throttle:browse')` to `GET /e/{slug}`, `GET /e/{slug}/gallery`, and `GET /e/{slug}/photos/{photo}/download`.
    - Leave the upload route `POST /e/{slug}/photos` on `throttle:uploads` ONLY (do not add browse to it — avoids double-counting and ambiguous 429 semantics).
    - _Requirements: 7.2, 6.1_

  - [x] 2.3 Register `SecurityHeaders` in the web middleware group **[NEW]**
    - In `bootstrap/app.php` append `SecurityHeaders::class` to the `$middleware->web(append: [...])` array, after the existing `HandleAppearance`, `HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`. Add the `use App\Http\Middleware\SecurityHeaders;` import.
    - _Requirements: 19.1, 19.4_

  - [x] 2.4 Add the event photo-cap guard to the upload controller **[NEW]**
    - In `app/Http/Controllers/PublicPhotoUploadController@store`, after the `abort_if(! $event->upload_enabled, 403, ...)` check and before the storage/dispatch loop, insert: `$incoming = count($request->file('photos'))`; `$currentCount = $event->photos()->count()` (SoftDeletes global scope excludes deleted → non-deleted count); `$maxPerEvent = (int) config('uploads.max_per_event', 500)`. If `$currentCount + $incoming > $maxPerEvent`, `Log::warning('Upload rejected: event photo cap exceeded', ['event_slug'=>$event->slug,'current_count'=>$currentCount,'incoming'=>$incoming,'max_per_event'=>$maxPerEvent])` then `abort(403, 'This event has reached its photo limit. Please contact the organizer.')`.
    - Add `use Illuminate\Support\Facades\Log;`. Leave every other line of `store()` byte-for-byte intact.
    - _Requirements: 11.2, 11.3, 11.4, 21.2_

  - [x] 2.5 Add sanitized validation-failure logging to the upload FormRequest **[NEW]**
    - In `app/Http/Requests/StorePhotosRequest`, override `protected function failedValidation(Validator $validator): void`: `Log::warning('Upload validation failed', ['event_slug'=>$this->route('slug'),'file_count'=>is_array($this->file('photos')) ? count($this->file('photos')) : 0,'first_error'=>array_key_first($validator->errors()->toArray())])`, then call `parent::failedValidation($validator)` to preserve the 422 + error bag.
    - Add `use Illuminate\Contracts\Validation\Validator;` and `use Illuminate\Support\Facades\Log;`. Do NOT change `rules()` or any other method.
    - _Requirements: 21.1, 21.4_

  - [x] 2.6 Add HTTP 429 handling to the PhotoUploader component **[NEW]**
    - In `resources/js/components/PhotoUploader.tsx` add a `rateLimited` state (reset to `false` at submit start), detect a 429 in the `useForm().post` `onError` handler, and render the exact copy `You've uploaded too many photos in a short period. Please wait a moment and try again.` in a block styled consistently with the existing error block (plain text, XSS-safe).
    - During implementation, confirm how the installed `@inertiajs/react` surfaces a 429 (onError payload vs. response-status check) and wire detection to that; the behavior (detect 429 → render the message) and the exact string are fixed.
    - _Requirements: 6.5, 9.2_

- [x] 3. Checkpoint - foundation and wiring in place
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Security test matrix: access control, isolation, exposure
  - [x] 4.1 Write `EventAccessSecurityTest` **[NEW]**
    - New file `tests/Feature/EventAccessSecurityTest.php`, `RefreshDatabase`. Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 1: Only active events are reachable`): active event resolves on every Public_Endpoint; draft, archived, soft-deleted, and nonexistent slugs each return HTTP 404 across the event page, gallery, and download endpoints.
    - Reference `PublicEventPageTest` / `PhotoGalleryTest`; add only the missing 404 matrix, do not duplicate existing assertions.
    - _Requirements: 22.1, 1.1, 1.2, 1.3, 1.4 — Property 1_

  - [x] 4.2 Write `EventIsolationTest` **[NEW]**
    - New file `tests/Feature/EventIsolationTest.php`, `RefreshDatabase`, `Storage::fake('public')`. Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 2: Photos are reachable only through their own event (IDOR)`): for two distinct active events A and B with a ready photo on B, requesting B's photo uuid under A's slug on the Download_Endpoint returns HTTP 404; resolution is via the event relationship.
    - _Requirements: 22.2, 2.1, 2.3, 2.4 — Property 2_

  - [x] 4.3 Write `DataExposureTest` **[NEW]**
    - New file `tests/Feature/DataExposureTest.php`, `RefreshDatabase`, `Storage::fake('public')`. Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 3: Public responses expose no internal identifiers or paths`): no raw storage path segment (e.g. `events/…/originals`), database primary/foreign key, event `uuid`, `user_id`, or internal timestamp appears in any Public_Endpoint response; and 404/403/429 bodies carry no path or SQL.
    - _Requirements: 22.10, 2.5, 15.1, 15.2, 15.3, 20.2 — Property 3_

- [x] 5. Security test matrix: uploads, cap, rate limits
  - [x] 5.1 Write `UploadSecurityTest` **[NEW]**
    - New file `tests/Feature/UploadSecurityTest.php`, `RefreshDatabase`, `Storage::fake('public')`, `Queue::fake()`. Include:
    - Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 4: Stored paths are server-generated and client fields are ignored`): a path-traversal filename (e.g. `../../evil.jpg`) plus injected client `event_id`/`status`/`original_path` yields a persisted `original_path` matching `events/{event_uuid}/originals/{uuid}.{ext}`, `event_id` = resolved event, `status` = server pending.
    - Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 7: Invalid uploads are rejected server-side`): unsupported format, oversized file, or over-count array → HTTP 422 with no photo persisted.
    - Example: a `.php` script payload is rejected (422). CSRF: a tokenless cross-site POST to the upload route is rejected. Assert a sanitized validation-failure `Log::warning` entry is emitted (no filenames/contents).
    - Reference `GuestPhotoUploadTest`; add only the missing traversal/mass-assignment/`.php`/CSRF/log cases.
    - _Requirements: 22.3, 22.7, 22.8, 22.9, 17.1, 21.1, 3.1, 3.2, 3.3, 3.4, 3.5, 5.1, 5.4, 16.1, 16.2, 16.3, 10.1, 10.2 — Properties 4, 7_

  - [x] 5.2 Write `EventPhotoCapTest` **[NEW]**
    - New file `tests/Feature/EventPhotoCapTest.php`, `RefreshDatabase`, `Storage::fake('public')`, `Queue::fake()`. Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 5: The event photo cap rejects exactly at the boundary`): with C non-deleted photos, N incoming, cap M — HTTP 403 with no photos stored and no jobs dispatched iff C+N>M, else accepted; soft-deleted photos excluded from C; boundary tested at exactly M; assert the cap `Log::warning` is emitted on rejection.
    - _Requirements: 22.11, 11.2, 11.3, 11.4, 21.2 — Property 5_

  - [x] 5.3 Write `RateLimitTest` **[NEW]**
    - New file `tests/Feature/RateLimitTest.php`, `RefreshDatabase`, `Storage::fake('public')`, `Queue::fake()`. Property test (>=100 iterations, tag `// Feature: security-rate-limiting, Property 6: Requests over the rate limit are rejected`): uploads over 10/min → 429; browse over 60/min → 429; a single multi-file upload counts once against the limit; window recovery via `$this->travel()->minutes(1)` (NO real sleep). Reset limiter state between cases (`RateLimiter::clear` or a fresh cache store).
    - _Requirements: 22.4, 22.5, 6.2, 6.3, 6.4, 7.1, 7.4, 8.1, 9.1 — Property 6_

- [x] 6. Security test matrix: headers, static protection, idempotence
  - [x] 6.1 Write `SecurityHeadersTest` **[NEW]**
    - New file `tests/Feature/SecurityHeadersTest.php`, `RefreshDatabase`. Example test: `X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN` present on representative web responses (a public event page and an asset/image response), and that response is still 200 (Inertia not broken).
    - _Requirements: 22.12, 19.2, 19.3, 19.4_

  - [x] 6.2 Write `MaliciousFileProtectionTest` **[NEW]**
    - New file `tests/Feature/MaliciousFileProtectionTest.php`. Smoke test: the tracked `storage/app/public/.htaccess` exists and its contents contain the engine-off directive and the `FilesMatch` denial block. Add a comment noting that real Apache non-execution is a documented manual check (task 8).
    - _Requirements: 4.2, 4.3_

  - [x] 6.3 Verify job idempotence coverage **[VERIFY]**
    - Inspect existing `tests/Feature/QueuedPhotoProcessingTest.php` for coverage of ProcessPhoto idempotence (a ready photo with both variants is unchanged on reprocess; ready photos are skipped). If it is already covered (e.g. `reprocessing_is_idempotent_no_duplicate_rows`), reference it and add NOTHING. Only if a gap exists, add a single assertion/test tagged `// Feature: security-rate-limiting, Property 8: Photo processing is idempotent`. Do NOT rewrite or duplicate the existing test, and do NOT refactor the job.
    - _Requirements: 12.2, 12.3 — Property 8_

- [x] 7. Checkpoint - all new security tests written
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Final verification **[NEW/VERIFY]**
  - Run `php artisan test --filter` for each new test file (`EventAccessSecurityTest`, `EventIsolationTest`, `DataExposureTest`, `UploadSecurityTest`, `EventPhotoCapTest`, `RateLimitTest`, `SecurityHeadersTest`, `MaliciousFileProtectionTest`).
  - Run the full `php artisan test` suite and confirm all Phase 1–8 tests remain green alongside the new security tests (additive changes must not regress existing behavior; fix any regression without refactoring shipped features).
  - Run `npx tsc --noEmit` to confirm the PhotoUploader change type-checks. Confirm `php artisan queue:work` still boots.
  - Documented manual checks (note only, no automation): (a) browsing an active event gallery works; (b) 429 appears after exceeding upload/browse limits; (c) 403 cap message appears near the limit; (d) a `.php` placed in `storage/app/public` is served as non-executable by Apache.
  - _Requirements: 22.13, 20.1, 20.3_

## Notes

- **[VERIFY]** tasks are tests only — do NOT refactor already-correct Phase 1–8 code. A red test means fix the test or investigate a genuine regression, never a rewrite of the shipped feature.
- **[NEW]** tasks are strictly additive: one config key set, one limiter, one middleware, three route middlewares, one controller guard clause, one FormRequest hook, one tracked `.htaccess`, one frontend branch. No existing method signature, route handler, model, or migration shape changes.
- Every property test runs a minimum of 100 iterations and is tagged `// Feature: security-rate-limiting, Property {n}: {property_text}` mapping to exactly one design property.
- New test files ADD only missing coverage; they reference (never duplicate or rewrite) `GuestPhotoUploadTest`, `ImageProcessingTest`, `QueuedPhotoProcessingTest`, `PhotoDownloadTest`, `PhotoGalleryTest`, `GalleryPayloadTest`, `PublicEventPageTest`.
- Rate-limit window recovery uses `$this->travel()->minutes(1)` — never real `sleep`.
- All tasks are coding/testing only; the manual checks in task 8 are documented notes, not automated steps.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3", "1.4"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3", "2.4", "2.5", "2.6"] },
    { "id": 2, "tasks": ["4.1", "4.2", "4.3", "5.1", "5.2", "5.3", "6.1", "6.2", "6.3"] },
    { "id": 3, "tasks": ["8"] }
  ]
}
```
