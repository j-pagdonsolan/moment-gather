# Implementation Plan: Automated Testing (MomentGather Phase 10)

## Overview

This plan implements MomentGather Phase 10 (Full Automated Testing): a backend-only PHPUnit
phase that **locks the existing 161-test regression floor** and **adds only the genuine coverage
gaps** identified in the requirements and design. Stack: Laravel 13 + React 19 + Inertia v3 +
TypeScript (frontend untouched), tests run on SQLite `:memory:` with array-backed
cache/session/mail drivers (full isolation from dev MariaDB). No `.env.testing` is created.

The plan follows the design deliverables in dependency order:

1. **Foundation** — additive factory states (`PhotoFactory`, `EventFactory`) and an OPTIONAL test-data
   helper trait. `definition()` is left intact for both factories so existing tests keep passing.
2. **New test files** — six new files (`EventValidationTest`, `PhotoStatusLifecycleTest`,
   `Unit/EventModelTest`, `Unit/PhotoModelTest`, `Unit/SlugGeneratorTest`, `Unit/PhotoProcessorTest`)
   that fill the R4, R11, R22, and R23 gaps, each scoped to avoid duplicating a locked file.
3. **Final verification** — filtered runs per new file, then the full `php artisan test` regression gate.

**Verify-only regression floor (NOT tasks — the untouched locked suite).** The following existing,
passing files are the 161-test regression floor. They are **not modified, refactored, or reorganized**
in this phase; they are simply left untouched and are enforced by the regression-guard runs (after
task 1 and in task 8): `tests/Feature/Auth/*` (R1), `DashboardTest` (R2), `EventTest` +
`EventAccessSecurityTest` (R3), `PublicEventPageTest` + `EventQrCodeTest` (R5, R6),
`GuestPhotoUploadTest` + `UploadSecurityTest` (R7, R8), `ImageProcessingTest` +
`QueuedPhotoProcessingTest` (R9, R10), `PhotoGalleryTest` + `GalleryPayloadTest` +
`PhotoDownloadTest` + `EventIsolationTest` (R12–R14), `DataExposureTest` + `RateLimitTest` +
`SecurityHeadersTest` + `MaliciousFileProtectionTest` (R15–R17), `EventPhotoCapTest` +
`ProcessPhotosCommandTest` + `Feature/SlugGeneratorTest` + `Settings/*` (R18–R21).

**Bug policy.** Production code under `app/**` is changed **only** if a NEW test reveals a genuine
bug; the fix is minimal and documented. Otherwise `app/**` is untouched. No frontend, no
`npx tsc --noEmit` (no frontend changes), no folder reorg, no new tooling.

## Tasks

- [x] 1. Foundation: additive factory states (test infrastructure)
  - [x] 1.1 [NEW] Add PhotoFactory states in `database/factories/PhotoFactory.php`
    - Add `pending()`: `status = STATUS_PENDING`, `optimized_path = null`, `thumbnail_path = null`.
    - Add `processing()`: `status = STATUS_PROCESSING`.
    - Add `failed()`: `status = STATUS_FAILED`.
    - Add `ready()`: `status = STATUS_READY` and set `optimized_path`/`thumbnail_path` derived from the
      event uuid + photo uuid, mirroring PhotoProcessor output exactly:
      `events/{eventUuid}/optimized/{photoUuid}.webp` and `events/{eventUuid}/thumbnails/{photoUuid}.webp`.
    - Because the event uuid is not known inside the state closure, resolve it via
      `afterMaking`/`afterCreating` reading `$photo->event->uuid` so paths match PhotoProcessor.
    - Do NOT alter `definition()` (additive only).
    - _Requirements: 24.1, 24.3_

  - [x] 1.2 [NEW] Add EventFactory states in `database/factories/EventFactory.php`
    - Add `active()`, `draft()`, `archived()` — each only overrides `status` to the matching value.
    - Do NOT alter `definition()` (additive only).
    - _Requirements: 24.2, 24.3_

  - [x] 1.3 [NEW] Regression guard — confirm additive states broke nothing
    - Run the FULL existing suite once: `php artisan test`.
    - Confirm the ≥161 existing tests still pass unchanged after adding the states.
    - _Requirements: 24.3, 26.1_

  - [ ]* 1.4 [NEW][OPTIONAL] Add `Tests\Concerns\CreatesTestData` helper trait
    - Only if it measurably reduces duplication in the NEW tests; skip otherwise.
    - `createOrganizer(): User` (User::factory()->create()).
    - `createActiveEvent(?User = null): Event` (uses `->active()`, `upload_enabled = true`).
    - `createReadyPhoto(?Event = null): Photo` (uses `->ready()`).
    - Do NOT refactor existing tests; used only by the new test classes.
    - _Requirements: 25.1_

- [x] 2. [NEW] Create `tests/Feature/EventValidationTest.php` (Event create/update validation + owner happy-path)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`, `actingAs($organizer)`.
  - Assert POST `/events` with no `name` → 422 (R4.1).
  - Assert `name` > 255 chars → 422 on create (POST `/events`) and update (PUT `/events/{uuid}` of owned) (R4.2).
  - Assert `description` > 5000 chars → 422 on create (R4.3).
  - Assert invalid `event_date` (e.g. `"not-a-date"`) → 422 on create (R4.4).
  - Assert PUT update with `status` not in `{active, archived}` (test both `'draft'` and `'bogus'`) → 422 (R4.5).
  - Assert create posting `status='archived'` + `upload_enabled=false` succeeds and persists
    `status='active'` + `upload_enabled=true` (assert via DB/model — controller forces these) (R4.6).
  - Assert create with a valid `name` persists a slugified `slug` (lowercase, hyphenated, no spaces);
    do NOT duplicate `SlugGeneratorTest` uniqueness/increment — reference it (R4.7).
  - Assert organizer GET `/events/{uuid}` of an OWNED event → 200 rendering `Events/Show`; one minimal
    explicit assertion (EventQrCodeTest already asserts owner 200 — do not duplicate QR-prop assertions) (R3.3).
  - Verify: `php artisan test --filter=EventValidationTest`.
  - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 3.3_

- [x] 3. [NEW] Create `tests/Feature/PhotoStatusLifecycleTest.php` (Photo status lifecycle)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`, `Storage::fake('public')`, GD synthesis.
  - Create `Photo::factory()->pending()` with a real GD original on the fake disk at its `original_path`;
    assert `status === STATUS_PENDING`; run `ProcessPhoto::dispatchSync($photo->id)`; after refresh assert
    `status === STATUS_READY` and both variant paths are set (R11.1).
  - Failure branch: use the container-bind pattern (`$this->instance(PhotoProcessor::class, <throwing>)`)
    OR dispatch against a missing original so the job/`failed()` path sets `STATUS_FAILED`; assert
    transition to `failed` (R11.2).
  - Gallery visibility: on an active event with one `ready` + one non-`ready` photo, the public gallery
    response contains only the `ready` one (R11.3).
  - Keep small/focused; reference `QueuedPhotoProcessingTest` and `PhotoGalleryTest` — do not duplicate.
  - Verify: `php artisan test --filter=PhotoStatusLifecycleTest`.
  - _Requirements: 11.1, 11.2, 11.3_

- [x] 4. [NEW] Create `tests/Unit/EventModelTest.php` (Event model unit tests)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`.
  - Assert `Event->user` is a `User` and `Event->photos` returns related `Photo` records (belongsTo + hasMany) (R22.1).
  - Assert creating an `Event` without an explicit `uuid` auto-generates a non-empty uuid (R22.2).
  - Assert `SoftDeletes`: after `delete()` the row is excluded from the default query, present via
    `withTrashed()`, and `deleted_at` is set (R22.3).
  - Assert casts: `event_date` is a Carbon date; `upload_enabled` is a bool.
  - Assert integrity: `Event.user_id` references the correct `User` (R22.6).
  - Verify: `php artisan test --filter=EventModelTest`.
  - _Requirements: 22.1, 22.2, 22.3, 22.6_

- [x] 5. [NEW] Create `tests/Unit/PhotoModelTest.php` (Photo model unit tests)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`.
  - Assert `Photo->event` is the correct `Event` (belongsTo) (R22.1).
  - Assert uuid auto-generated when created without one (R22.2).
  - Assert `SoftDeletes` (excluded from default query, retained via `withTrashed()`, `deleted_at` set) (R22.3).
  - Assert `STATUS_` constants equal `'pending'`, `'processing'`, `'ready'`, `'failed'`, `'deleted'` (R22.4).
  - With `Storage::fake('public')`: `optimizedUrl()`/`thumbnailUrl()` fall back to `original_path` when
    null, and use their own path when set (R22.5).
  - Assert integrity: `Photo.event_id` references the correct `Event`; no orphan by design (R22.6).
  - Verify: `php artisan test --filter=PhotoModelTest`.
  - _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6_

- [x] 6. [NEW] Create `tests/Unit/SlugGeneratorTest.php` (SlugGenerator service unit tests)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`; drive `app(SlugGenerator::class)` directly.
  - Assert `generate('Some Name')` → `'some-name'`; punctuation/multi-space collapse to single hyphens;
    leading/trailing hyphens trimmed.
  - Assert `generate('!!!')` (no alphanumerics) → `''` (empty-name signal for the uuid-fallback caller).
  - Assert `generateFromUuid($uuid)` returns the first 12 hex chars (hyphens stripped).
  - Assert uniqueness/increment: with existing `Event` slug `party`, `generate('Party')` → `party-2`;
    with `party` and `party-2` present → `party-3`.
  - Coexists with `tests/Feature/SlugGeneratorTest.php` (namespace `Tests\Unit` — no collision).
  - Verify: `php artisan test --filter=SlugGeneratorTest` (runs BOTH feature + unit — expected).
  - _Requirements: 23.1_

- [x] 7. [NEW] Create `tests/Unit/PhotoProcessorTest.php` (PhotoProcessor service unit tests)
  - `#[Test]`, extends `Tests\TestCase`, `use RefreshDatabase;`, `Storage::fake('public')`.
  - Setup: GD-synthesize an original, write bytes to `events/{eventUuid}/originals/{photoUuid}.jpg`,
    then call `app(PhotoProcessor::class)->process($originalPath, $eventUuid, $photoUuid)`.
  - Assert returned `optimized_path`/`thumbnail_path` exist on disk and are decodable `image/webp`; a large
    input (4000×3000) → optimized max dim ≤ 2048 and thumbnail max dim ≤ 500 (R23.2).
  - Assert a small input (300×200) is NOT upscaled — optimized/thumbnail dims do not exceed the original (R23.3).
  - Assert a non-image input (non-image bytes or `tests/Fixtures/not-an-image.jpg`) causes `process()` to
    throw (`expectException`) (R23.4).
  - Reference `ImageProcessingTest` (HTTP-level); this file drives the service in isolation.
  - Verify: `php artisan test --filter=PhotoProcessorTest`.
  - _Requirements: 23.2, 23.3, 23.4_

- [x] 8. [NEW] Final verification and regression gate
  - Run each new file via filter: `php artisan test --filter=EventValidationTest`,
    `--filter=PhotoStatusLifecycleTest`, `--filter=EventModelTest`, `--filter=PhotoModelTest`,
    `--filter=SlugGeneratorTest` (feature + unit), `--filter=PhotoProcessorTest`.
  - Run the FULL suite: `php artisan test`; confirm ALL pass with ≥ 161 existing tests + all new tests green (R26.1).
  - Confirm meaningful coverage areas are hit: business logic, authorization, public endpoints, uploads,
    image processing, queued processing, security boundaries (R26.2). Do NOT chase 100% coverage.
  - If any NEW test reveals a genuine production bug, fix it MINIMALLY in `app/**` and document it;
    otherwise `app/**` remains untouched.
  - Document run commands: full `php artisan test`; filtered `php artisan test --filter=<Name>` (R26.3).
  - _Requirements: 26.1, 26.2, 26.3_

## Notes

- **Regression floor is verify-only.** The 161 existing tests and the production code they cover are NOT
  rewritten, refactored, or reorganized. They appear in the Overview as the untouched locked suite, not as
  tasks; "verify" is enforced by the regression-guard runs in task 1.3 and task 8.
- **Additive factory states.** `definition()` is left intact in both factories so existing tests keep
  passing (R24.3).
- **Anti-duplication.** Each new file is scoped to its gap and references the locked file it must not
  duplicate (per the design's anti-duplication table).
- **No property tests added.** Property/fuzz coverage already exists in the locked suite
  (`UploadSecurityTest`, `EventIsolationTest`); this phase adds example/edge-case verification only, so
  there is no Correctness Properties section and no property test sub-tasks.
- **Optional task.** Only task 1.4 (helper trait) is marked optional (`*`); all other sub-tasks are required.
- **Backend only.** No frontend tests, no `npx tsc --noEmit`, no folder reorg, no new tooling.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3", "1.4"] },
    { "id": 1, "tasks": ["2", "3", "4", "5", "6", "7"] },
    { "id": 2, "tasks": ["8"] }
  ]
}
```
