# Design Document

## Overview

MomentGather Phase 10 (Full Automated Testing) is a **testing/verification phase, not a feature phase**. This design describes the concrete test artifacts (new test files, new factory states, optional helpers) that lock in the correctness of Phases 1–9 and fill the genuine coverage gaps identified in the requirements.

### Guiding principles

- **Verify-vs-new.** Every deliverable is either a *lock* on existing behavior (an already-passing file that must keep passing) or a *new* artifact that fills a genuine gap. Existing passing tests are referenced, not rewritten.
- **Lock existing coverage.** The suite already contains **161 passing tests** (Phases 5–9). That number is the **regression floor**: after this phase the full run must pass with **≥ 161 tests plus the new ones**, all green. No existing test file is modified, refactored, or reorganized.
- **Add gaps only.** New tests are written only for the behaviors the requirements tag *New coverage*: event create/update validation (R4), photo status lifecycle (R11), model unit tests (R22), service unit tests (R23), factory states (R24), optional helpers (R25).
- **Backend only.** No JavaScript/Vitest/Playwright toolchain. PHPUnit is the sole test surface (per Out-of-Scope in requirements).
- **Flat structure.** New files sit flat under `tests/Feature/*` and `tests/Unit/*`. No folder reorganization.
- **Production changes only on a real bug.** Production code (`app/**`) is touched only if a *new* test discovers a genuine defect. If that happens, the fix is minimal and documented; otherwise `app/**` is untouched.
- **No arbitrary coverage target.** The goal is meaningful coverage of business logic, authorization, public endpoints, uploads, processing, and security boundaries — not 100%.

### Ground truth this design was written against (verified by reading source)

- `App\Services\SlugGenerator`: `generate(string $name, ?int $excludeId = null): string` (returns `''` when the name has no `[a-z0-9]` characters; otherwise slugifies to lowercase, collapses non-alphanumeric runs to `-`, trims `-`, then de-dupes via `-2`, `-3`, … against `Event::withoutTrashed()`). `generateFromUuid(string $uuid): string` (first 12 hex chars of the uuid, de-duped).
- `App\Services\PhotoProcessor::process(string $originalPath, string $eventUuid, string $photoUuid): array` → `['optimized_path' => "events/{eventUuid}/optimized/{photoUuid}.webp", 'thumbnail_path' => "events/{eventUuid}/thumbnails/{photoUuid}.webp"]`. Reads the original from `Storage::disk('public')`, writes WebP variants via Intervention `scaleDown` (optimized ≤ 2048, thumbnail ≤ 500, **scale-down only, never upscales**), throws on decode/encode/storage failure.
- `App\Policies\EventPolicy`: `view/update/delete` each return `$user->id === $event->user_id`.
- `App\Jobs\ProcessPhoto`: `tries=3`, `backoff()=[10,30,60]`; `handle()` re-fetches `Photo::find($photoId)`, no-ops on missing photo, skips already-ready-with-both-paths, marks `FAILED` when the original is missing, else sets `PROCESSING` → calls `PhotoProcessor::process` → sets both paths + `READY`. `failed()` deletes partial variants and sets `FAILED`.
- `App\Models\Event`: `SoftDeletes`; `belongsTo(User)`; `hasMany(Photo)`; uuid auto-generated in `booted() creating` when empty; casts `event_date=date`, `upload_enabled=boolean`, `deleted_at=datetime`; `getRouteKeyName()='uuid'`.
- `App\Models\Photo`: `SoftDeletes`; `belongsTo(Event)`; uuid auto-generated in `booted() creating`; `STATUS_PENDING/PROCESSING/READY/FAILED/DELETED`; `originalUrl()` uses `original_path`; `optimizedUrl()`/`thumbnailUrl()` fall back to `original_path` when their path is null; casts `file_size/width/height=integer`, `deleted_at=datetime`.
- `Tests\Unit\ExampleTest` extends `Tests\TestCase` and uses `RefreshDatabase` — confirming Unit tests in this project touch the DB and use the same base + trait as Feature tests.
- `EventFactory::definition()` sets `status` = random `active|archived`, computes a real slug, has **no states**. `PhotoFactory::definition()` sets `status = STATUS_READY`, no `optimized_path`/`thumbnail_path`, has **no states**.

## Architecture

### Runtime & isolation

Tests run under `phpunit.xml`: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`. In-memory SQLite + array drivers fully isolate the run from the development MariaDB (XAMPP). No `.env.testing` is created.

### Test conventions (all new tests follow these)

- PHPUnit `#[Test]` attribute, class extends `Tests\TestCase`, `use RefreshDatabase;`.
- Storage: `Storage::fake('public')` in every test that reads/writes disk paths or asserts variants.
- Queue: `Queue::fake()` where the intent is "job is enqueued but not run"; `ProcessPhoto::dispatchSync($photo->id)` where the intent is "run the job synchronously now" (the pattern already used in `ImageProcessingTest`).
- Images: **GD synthesis** — `imagecreatetruecolor()` + `imagejpeg/png/webp()` to a `tempnam()` file, wrapped as a test-mode `UploadedFile`, matching `ImageProcessingTest::makeImage()`. Service-level unit tests write the synthesized bytes directly to `Storage::fake('public')` at an `events/{uuid}/originals/...` path.
- Auth: organizer flows use `$this->actingAs($organizer)`.
- Carbon: `travel*()` only where the existing suite already uses it (e.g. rate-limit windows); the new tests here do not introduce new time-travel.

### Anti-duplication policy (which existing test each new test references)

Each new test is scoped to the *gap* and explicitly avoids re-testing what a locked file already covers:

| New file | References (must NOT duplicate) |
|---|---|
| `EventValidationTest` | `EventTest` (CRUD/ownership), `EventAccessSecurityTest` (403 boundaries), `SlugGeneratorTest` (slug uniqueness/suffixes), `EventQrCodeTest` (owner 200 `Events/Show`) |
| `PhotoStatusLifecycleTest` | `QueuedPhotoProcessingTest` (full queue behavior), `PhotoGalleryTest` (broad gallery visibility) |
| `Unit/EventModelTest`, `Unit/PhotoModelTest` | Feature tests that exercise the same behavior via HTTP — these assert at the model level instead |
| `Unit/SlugGeneratorTest` | `tests/Feature/SlugGeneratorTest` (feature-level) — the unit test drives the service directly |
| `Unit/PhotoProcessorTest` | `ImageProcessingTest` (HTTP-driven) — the unit test drives the service directly |

## Components and Interfaces

The "components" of this testing phase are test files, factory states, and optional helpers. No new production classes are designed.

### New test files

#### 1. `tests/Feature/EventValidationTest.php` — Requirement 4 (and R3.3)

Drives the organizer routes with `actingAs($organizer)`. Asserts:

- **R4.1** POST `/events` with no `name` → **422**.
- **R4.2** `name` > 255 chars → **422** on both create (POST `/events`) and update (PUT `/events/{uuid}` of an owned event).
- **R4.3** `description` > 5000 chars → **422** (create).
- **R4.4** invalid `event_date` (e.g. `"not-a-date"`) → **422** (create).
- **R4.5** update with `status` not in `{active, archived}` (test both `'draft'` and `'bogus'`) → **422**.
- **R4.6** create while posting `status='archived'` and `upload_enabled=false` → request succeeds and the persisted `Event` has `status='active'` and `upload_enabled=true` (assert via DB / model, since the controller **forces** these).
- **R4.7** create with a valid `name` → the persisted `slug` is present and slugified (e.g. lowercase, hyphenated, no spaces). Do **not** re-assert uniqueness/increment behavior — that is `SlugGeneratorTest`'s job; reference it.
- **R3.3 (positive policy happy-path)** organizer GET `/events/{uuid}` of an **owned** event → **200** rendering `Events/Show`.
  - *Note:* `EventQrCodeTest::owner_receives_public_url_prop_on_show` already asserts an owner 200 rendering `Events/Show`. R3.3 is therefore satisfiable **by reference**. This design adds a distinct explicit assertion here (a single small test) so the happy-path lives next to the validation gaps; it is intentionally minimal and does not duplicate the QR-prop assertions.

#### 2. `tests/Feature/PhotoStatusLifecycleTest.php` — Requirement 11

A small, focused lifecycle document. Uses `Storage::fake('public')` and GD synthesis. Asserts:

- **R11.1** Create a photo via `Photo::factory()->pending()` with a real GD original placed on the fake disk at its `original_path`; assert `status === STATUS_PENDING`. Run `ProcessPhoto::dispatchSync($photo->id)`; after refresh assert `status === STATUS_READY` and both variant paths are set. The intermediate `PROCESSING` state is written by the job before calling the processor; this design documents it as **transient** (the synchronous run lands on `READY`), and asserts the `PROCESSING` value directly via a model update in the failure setup rather than trying to catch a mid-flight state.
- **R11.2** Failure branch: place a photo in `PROCESSING` (or dispatch with a missing original) so the job/`failed()` path sets `STATUS_FAILED`; assert transition to `failed`. Simulate failure using the container-bind pattern from `ImageProcessingTest` (bind a throwing `PhotoProcessor`) **or** the missing-original branch in `ProcessPhoto::handle()`.
- **R11.3** Gallery visibility: with one `ready` and one non-`ready` photo on an active event, the public gallery response contains only the `ready` one.
- Keeps to a handful of assertions; the full queue matrix stays in `QueuedPhotoProcessingTest` and broad gallery rules stay in `PhotoGalleryTest` (referenced, not duplicated).

#### 3. `tests/Unit/EventModelTest.php` — Requirement 22

Extends `Tests\TestCase`, `use RefreshDatabase;` (Eloquent needs the DB; matches `ExampleTest`). Asserts:

- **R22.1** `Event->user` is a `User`; `Event->photos` returns the related `Photo` records (`hasMany`).
- **R22.2** Creating an `Event` without an explicit `uuid` auto-generates one (non-empty, uuid-shaped).
- **R22.3** `SoftDeletes`: after `delete()`, the row is excluded from the default query, still present via `withTrashed()`, and `deleted_at` is set.
- **R22.6 (integrity)** `Event.user_id` references the correct `User`.
- Casts: `event_date` is a Carbon date; `upload_enabled` is a bool.

#### 4. `tests/Unit/PhotoModelTest.php` — Requirement 22

Extends `Tests\TestCase`, `use RefreshDatabase;`. Asserts:

- **R22.1** `Photo->event` is the correct `Event` (`belongsTo`).
- **R22.2** uuid auto-generated when created without one.
- **R22.3** `SoftDeletes` (excluded from default query, retained via `withTrashed()`, `deleted_at` set).
- **R22.4** status constants equal their literal values: `pending`, `processing`, `ready`, `failed`, `deleted`.
- **R22.5** With `Storage::fake('public')`: when `optimized_path`/`thumbnail_path` are **null**, `optimizedUrl()`/`thumbnailUrl()` return the URL derived from `original_path`; when set, they return the URL derived from their own path.
- **R22.6 (integrity)** `Photo.event_id` references the correct `Event`; no orphan is created by design (a photo is always built against an event).

#### 5. `tests/Unit/SlugGeneratorTest.php` — Requirement 23.1

Extends `Tests\TestCase`, `use RefreshDatabase;`. Drives the service directly via `app(SlugGenerator::class)`.

- **Naming/collision:** the existing feature test is `tests/Feature/SlugGeneratorTest.php`. The new file lives in `tests/Unit` (different namespace `Tests\Unit`), so there is **no class/file collision**.
- Asserts (pure-logic focus, complementary to the feature test):
  - `generate('Some Name')` slugifies to `some-name`; punctuation/multiple spaces collapse to single hyphens; leading/trailing hyphens trimmed.
  - `generate('!!!')` (no alphanumeric chars) returns `''` — the empty-name signal that triggers the caller's `generateFromUuid` fallback.
  - `generateFromUuid($uuid)` returns the first 12 hex chars (hyphens stripped) as the base.
  - uniqueness/increment: with an existing `Event` whose slug is `party`, `generate('Party')` returns `party-2`; with `party` and `party-2` present, returns `party-3`. (Direct-on-service edge coverage; references the feature test rather than re-asserting its HTTP path.)

#### 6. `tests/Unit/PhotoProcessorTest.php` — Requirement 23.2–23.4

Extends `Tests\TestCase`, `use RefreshDatabase;`. Drives `app(PhotoProcessor::class)->process(...)` directly with `Storage::fake('public')`.

- Setup: GD-synthesize an image, write bytes to the fake disk at `events/{eventUuid}/originals/{photoUuid}.jpg`, then call `process($originalPath, $eventUuid, $photoUuid)`.
- **R23.2** Returned `optimized_path`/`thumbnail_path` exist on the disk and are decodable WebP; a **large** input (e.g. 4000×3000) yields optimized max dimension ≤ 2048 and thumbnail max dimension ≤ 500.
- **R23.3** A **small** input (e.g. 300×200) is **not upscaled** — optimized and thumbnail dimensions do not exceed the original.
- **R23.4** A **non-image** input (write `not-an-image` bytes, or use `tests/Fixtures/not-an-image.jpg`) causes `process()` to **throw** (`expectException`).
- References `ImageProcessingTest` (which exercises the same guarantees via HTTP); this file verifies the service in isolation.

### Factory state additions — Requirement 24

States are **additive**: `definition()` is left intact so existing tests that rely on the default definition keep passing (R24.3).

`database/factories/PhotoFactory.php`:

```php
public function pending(): static
{
    return $this->state(fn () => [
        'status'         => Photo::STATUS_PENDING,
        'optimized_path' => null,
        'thumbnail_path' => null,
    ]);
}

public function processing(): static
{
    return $this->state(fn () => ['status' => Photo::STATUS_PROCESSING]);
}

public function failed(): static
{
    return $this->state(fn () => ['status' => Photo::STATUS_FAILED]);
}

public function ready(): static
{
    // Derive processed paths from the event + photo uuid, mirroring PhotoProcessor output.
    return $this->state(function (array $attributes) {
        $uuid = $attributes['uuid'] ?? (string) Str::uuid();

        return [
            'uuid'           => $uuid,
            'status'         => Photo::STATUS_READY,
            'optimized_path' => "events/EVENT_UUID/optimized/{$uuid}.webp",
            'thumbnail_path' => "events/EVENT_UUID/thumbnails/{$uuid}.webp",
        ];
    });
}
```

> Implementation note for `ready()`: the event uuid is not known inside the state closure without the related event. During implementation, resolve it via `afterMaking`/`afterCreating` (read `$photo->event->uuid`) so the paths match `PhotoProcessor` output exactly. The shape above shows intent; the final implementation sets the paths once the event uuid is available.

`database/factories/EventFactory.php`:

```php
public function active(): static
{
    return $this->state(fn () => ['status' => 'active']);
}

public function draft(): static
{
    return $this->state(fn () => ['status' => 'draft']);
}

public function archived(): static
{
    return $this->state(fn () => ['status' => 'archived']);
}
```

- **R24.3 (regression guard):** adding these states must not alter `definition()`. After adding them, the full suite must still pass unchanged.

### Optional helpers — Requirement 25 (OPTIONAL)

Only added **if** they measurably reduce duplication in the *new* tests, and only for the new tests — existing tests are not refactored.

- `createOrganizer(): User` — `User::factory()->create()`.
- `createActiveEvent(?User $organizer = null): Event` — `Event::factory()->active()->for($organizer ?? $this->createOrganizer())->create(['upload_enabled' => true])`.
- `createReadyPhoto(?Event $event = null): Photo` — `Photo::factory()->ready()->for($event ?? $this->createActiveEvent())->create()`.

Placement: a small `Tests\Concerns\CreatesTestData` trait used by the new test classes, **or** protected methods added to nothing existing. Marked OPTIONAL; skip if it does not clearly help. Do **not** introduce a large base-class refactor.

### Verify-only (locked) — regression floor

These existing files must keep passing and **must not be modified** (grouped). They constitute the 161-test regression floor:

- **Auth (R1):** `tests/Feature/Auth/*`
- **Dashboard (R2):** `DashboardTest`
- **Event CRUD / authorization (R3):** `EventTest`, `EventAccessSecurityTest`
- **Public event page / QR (R5, R6):** `PublicEventPageTest`, `EventQrCodeTest`
- **Uploads / upload security (R7, R8):** `GuestPhotoUploadTest`, `UploadSecurityTest`
- **Image / queued processing (R9, R10):** `ImageProcessingTest`, `QueuedPhotoProcessingTest`
- **Gallery / download / isolation (R12, R13, R14):** `PhotoGalleryTest`, `GalleryPayloadTest`, `PhotoDownloadTest`, `EventIsolationTest`
- **Data exposure / rate limit / headers / malicious files (R15–R17):** `DataExposureTest`, `RateLimitTest`, `SecurityHeadersTest`, `MaliciousFileProtectionTest`
- **Photo cap / command / slug (feature) / settings (R18–R21):** `EventPhotoCapTest`, `ProcessPhotosCommandTest`, `SlugGeneratorTest`, `Settings/*`

## Data Models

- **No schema changes.** No new migrations, no `.env.testing`.
- **Fixtures reused:** `tests/Fixtures/sample.jpg|jpeg|png|webp` and `not-an-image.jpg` for format/negative cases; GD synthesis for size-specific cases (large ≤2048, small no-upscale).
- **Disk:** `Storage::fake('public')` in every disk-touching test. Service unit tests write synthesized originals to `events/{uuid}/originals/...` and read the produced variants back off the fake disk.

## Error Handling

- **Processing failure (R11.2, references R9):** simulate by container-binding a throwing `PhotoProcessor` (`$this->instance(PhotoProcessor::class, ...)` — the pattern `ImageProcessingTest` uses), or by dispatching against a **missing original** so `ProcessPhoto::handle()` marks `FAILED` directly. `failed()` cleanup of partial variants is already covered by `ImageProcessingTest`; the lifecycle test only asserts the resulting `failed` status.
- **Invalid validation input (R4):** oversized `name`/`description` strings via `str_repeat`, malformed `event_date`, out-of-set `status`; assert HTTP 422 and (for forced fields) DB values.
- **Non-image service input (R23.4):** non-image bytes or `not-an-image.jpg`; assert `process()` throws.
- **No orphan-by-design (R22.6):** photos are always built via `->for($event)` / `Photo::factory()->for(...)`; the test asserts the parent linkage rather than attempting to construct an orphan.

## Testing Strategy

This is a test phase, so the "strategy" is the execution plan:

1. **Add factory states** (`PhotoFactory`, `EventFactory`) additively; keep `definition()` intact.
2. **Add new test files** (5 above) plus the optional helper trait if it earns its place.
3. **Run targeted filters** per new file during development, e.g.:
   - `php artisan test --filter=EventValidationTest`
   - `php artisan test --filter=PhotoStatusLifecycleTest`
   - `php artisan test --filter=EventModelTest`
   - `php artisan test --filter=PhotoModelTest`
   - `php artisan test --filter=SlugGeneratorTest` (runs both feature + unit — expected)
   - `php artisan test --filter=PhotoProcessorTest`
4. **Run the full suite as the regression gate:** `php artisan test` must pass with **≥ 161 existing tests + the new tests**, all green (R26.1).
5. **Bug policy:** if a new test reveals a genuine production bug, fix it minimally in `app/**` and document the fix; otherwise production code is untouched (R26.2). **Do not chase 100% coverage.**
6. **Run docs (R26.3):** full run `php artisan test`; filtered run `php artisan test --filter=<TestOrMethodName>`.

Property-based / fuzz-style coverage (upload-security ~120 iterations, isolation ~100 iterations) already exists in the locked suite (`UploadSecurityTest`, `EventIsolationTest`); this phase does not add new property tests, so no Correctness Properties section is generated for new production logic. The new work is example/edge-case verification of shipped behavior, which is why unit + focused feature tests are the right tools here rather than new PBT.

## Design Decisions & Rationale

- **Unit tests use `RefreshDatabase`.** The models and services under test (Eloquent relationships, uuid generation, soft deletes, slug uniqueness against the DB) require a database. `ExampleTest` already extends `Tests\TestCase` with `RefreshDatabase`, so the new unit tests follow the same base — consistent and correct for this codebase.
- **`Unit/SlugGeneratorTest` coexists with `Feature/SlugGeneratorTest`.** Different namespaces (`Tests\Unit` vs `Tests\Feature`) mean no collision. The unit test targets pure-logic edges (empty-name → `''`, `generateFromUuid` base, increment sequence) directly on the service; the feature test keeps its HTTP-level assertions. `--filter=SlugGeneratorTest` intentionally runs both.
- **Factory states are additive.** Leaving `definition()` untouched guarantees the 161 existing tests (which depend on the default definition) keep passing (R24.3). States only *override* specific attributes when explicitly requested.
- **`ready()` derives paths from the event + photo uuid.** This mirrors `PhotoProcessor` output (`events/{eventUuid}/optimized|thumbnails/{photoUuid}.webp`) so gallery/payload assertions using the state see realistic paths.
- **No folder reorg, no frontend tests.** Both are explicit user decisions in the requirements' Out-of-Scope. Preserving the flat layout avoids churn in the locked suite; backend-only keeps the phase focused.
- **`processing` is transient.** In a synchronous `dispatchSync` run the job sets `PROCESSING` and immediately proceeds to `READY`, so there is no observable pause to assert mid-flight. The lifecycle test documents the state's existence (via the constant and the failure-path setup) rather than racing to catch it.

## Correctness Properties

Although this phase adds no new property-based tests, the new tests reinforce (and the locked suite already enforces) these system invariants, which together form the regression floor:

- **Ownership isolation.** An organizer can act only on their own events; cross-user update/delete → 403 (`EventTest`, `EventAccessSecurityTest`; positive owner-view happy-path added by `EventValidationTest`).
- **Server-authoritative fields.** `status` and `upload_enabled` on create are server-forced regardless of client input (`EventValidationTest` R4.6); server-generated upload paths cannot be overridden by clients (`UploadSecurityTest`).
- **Public visibility gate.** Only `active` events and only `ready` photos are publicly visible; draft/archived/soft-deleted → 404 (`PublicEventPageTest`, `PhotoGalleryTest`, `PhotoStatusLifecycleTest` R11.3).
- **Cross-event isolation.** A photo uuid from one event is not reachable through another (`EventIsolationTest`).
- **Processing safety.** Failure marks the photo `failed`, cleans up partial variants, and preserves the original (`ImageProcessingTest`, `QueuedPhotoProcessingTest`; lifecycle transition asserted by `PhotoStatusLifecycleTest` R11.2).
- **No data leakage.** Internal ids/paths/SQL never appear in responses or error bodies (`DataExposureTest`).

Every design element above traces to a requirement: R3.3, R4.1–R4.7 → `EventValidationTest`; R11.1–R11.3 → `PhotoStatusLifecycleTest`; R22.1–R22.6 → `Unit/EventModelTest` + `Unit/PhotoModelTest`; R23.1 → `Unit/SlugGeneratorTest`; R23.2–R23.4 → `Unit/PhotoProcessorTest`; R24.1–R24.3 → factory states; R25 → optional helpers; R1–R21 → verify-only locked files; R26 → the execution/regression strategy.
