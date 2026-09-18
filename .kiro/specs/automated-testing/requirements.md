# Requirements Document

## Introduction

MomentGather Phase 10 (Full Automated Testing) establishes comprehensive automated test coverage of **existing, shipped behavior**. This is a **testing/verification phase, not a feature phase**. No new product features are introduced. The goal is to lock in the correctness of Phases 1–9 with a durable regression suite and to fill genuine coverage gaps.

**Verify-vs-new framing (critical).** The suite already contains **161 passing tests** from Phases 5–9. Every requirement below is tagged as one of:

- **(Already covered — verify/keep)**: The behavior is already exercised by an existing, passing test file. The requirement locks that coverage in place. The Test_Suite SHALL continue to include and pass these tests. Existing passing tests and the production code they cover MUST NOT be rewritten, refactored, or reorganized as part of this phase.
- **(New coverage)**: A genuine gap. The requirement specifies exactly what new tests must assert.

Production code is modified **only** if a new test discovers a genuine bug. This phase does not refactor production code for its own sake.

**Test environment and isolation.** The project uses **PHPUnit** (not Pest). `phpunit.xml` configures the test run with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, and `BCRYPT_ROUNDS=4`. The in-memory SQLite database plus array-backed cache/session/mail drivers **fully isolate the test run from the development MariaDB (XAMPP)**. No `.env.testing` file is needed and none SHALL be created. Tests use PHPUnit `#[Test]` attributes, extend `Tests\TestCase`, and use `RefreshDatabase`.

**Scope decisions (encoded by user):**

- **Backend only.** Frontend / JavaScript tests are **out of scope** for this phase. No Vitest or JS test toolchain is introduced. The backend PHPUnit suite is the sole focus.
- **Flat structure kept.** Tests remain flat under `tests/Feature/*` and `tests/Unit/*`. Existing tests are **not** reorganized into subfolders. New tests are added flat alongside existing ones.
- **Factory states allowed.** Adding named states to `PhotoFactory` and `EventFactory` is permitted test-infrastructure work. Adding states MUST NOT break existing passing tests.

## Glossary

- **Test_Suite**: The complete set of automated tests executed by `php artisan test`, spanning `tests/Feature` and `tests/Unit`.
- **Feature_Test**: A test residing in `tests/Feature` that exercises an HTTP endpoint, job, command, or multi-component flow, typically using `RefreshDatabase`.
- **Unit_Test**: A test residing in `tests/Unit` that exercises a single class (model, service) in isolation, without HTTP routing.
- **Factory_State**: A named Eloquent factory modifier (e.g., `PhotoFactory::ready()`) that produces a model in a specific, meaningful state.
- **Already_Covered**: A behavior already exercised by an existing, passing test file; the requirement locks it in and forbids rewriting it.
- **New_Coverage**: A genuine gap where new tests must be added in this phase.
- **Regression**: An unintended break in previously working behavior, detected by a failing test that previously passed.
- **Organizer**: An authenticated `User` who owns one or more `Event` records.
- **Guest**: An unauthenticated visitor interacting with public endpoints.
- **Public_Endpoint**: A route reachable without authentication (public event page `/e/{slug}`, gallery, guest upload, photo download).
- **Photo_Lifecycle**: The `Photo.status` progression `pending → processing → ready`, with `processing → failed` as the error branch. Only `ready` photos are gallery-visible.
- **GD_Synthesis**: The test pattern of generating a real in-memory image via PHP GD and writing it to `Storage::fake('public')`, reused from `ImageProcessingTest`.
- **PhotoProcessor**: `App\Services\PhotoProcessor::process(originalPath, eventUuid, photoUuid)` → `['optimized_path','thumbnail_path']`; produces WebP variants (optimized ≤ 2048, thumbnail ≤ 500, scale-down only), throws on failure.
- **SlugGenerator**: `App\Services\SlugGenerator` with `generate(name, excludeId?)` and `generateFromUuid(uuid)`; slugifies, enforces uniqueness with incrementing suffixes, and returns empty string when a name has no alphanumeric characters (caller falls back to `generateFromUuid`).

## Requirements

### Requirement 1: Authentication Coverage

**User Story:** As a maintainer, I want authentication behavior locked by tests, so that login, logout, verification, and two-factor flows never silently regress.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass authentication coverage in `tests/Feature/Auth/` (login, logout, invalid credentials, two-factor redirect, rate limiting, email verification, password reset, password confirmation, registration, two-factor challenge, verification notification). *(Already covered — verify/keep)*

### Requirement 2: Dashboard Coverage

**User Story:** As a maintainer, I want the organizer dashboard behavior locked by tests, so that authentication gating and stat scoping stay correct.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass dashboard coverage in `tests/Feature/DashboardTest.php` (guest redirected to login, authenticated user can visit, stats scoped to the user, soft-deleted records excluded from stats). *(Already covered — verify/keep)*

### Requirement 3: Event CRUD and Authorization Coverage

**User Story:** As a maintainer, I want event ownership and CRUD authorization locked by tests, so that organizers can only manage their own events.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass event CRUD and ownership coverage in `tests/Feature/EventTest.php` (guest redirected to login on organizer routes, create event with a name, created event belongs to the creating user, multiple events per user, update own event, soft-delete own event, updating or deleting another user's event returns 403). *(Already covered — verify/keep)*
2. THE Test_Suite SHALL continue to include and pass authorization boundary coverage in `tests/Feature/EventAccessSecurityTest.php`. *(Already covered — verify/keep)*
3. WHEN an Organizer requests the show route for an event they own, THE EventController SHALL return a 200 response rendering the `Events/Show` page. *(New coverage — add a positive policy happy-path assertion if not already asserted)*

### Requirement 4: Event Create/Update Validation

**User Story:** As a maintainer, I want event input validation locked by tests, so that malformed or malicious event input is rejected and server-forced fields cannot be overridden by clients.

#### Acceptance Criteria

1. WHEN an Organizer submits a create-event request with no `name`, THE EventController SHALL reject the request with HTTP 422. *(New coverage)*
2. IF a create or update request includes a `name` longer than 255 characters, THEN THE EventController SHALL reject the request with HTTP 422. *(New coverage)*
3. IF a create or update request includes a `description` longer than 5000 characters, THEN THE EventController SHALL reject the request with HTTP 422. *(New coverage)*
4. IF a create or update request includes an invalid `event_date`, THEN THE EventController SHALL reject the request with HTTP 422. *(New coverage)*
5. WHEN an Organizer submits an update-event request with a `status` value other than `active` or `archived` (for example `draft` or arbitrary text), THE EventController SHALL reject the request with HTTP 422. *(New coverage)*
6. WHEN an Organizer creates an event and the request attempts to set `status` or `upload_enabled`, THE EventController SHALL persist `status='active'` and `upload_enabled=true` regardless of client-supplied values. *(New coverage)*
7. WHEN an Organizer creates an event, THE EventController SHALL generate the event `slug` from the event `name`. *(New coverage — slug uniqueness and incrementing suffixes are validated by `SlugGeneratorTest`; reference, do not duplicate)*

### Requirement 5: Public Event Page Coverage

**User Story:** As a maintainer, I want the public event page behavior locked by tests, so that only active events are publicly visible and private organizer data is never exposed.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass public event page coverage in `tests/Feature/PublicEventPageTest.php` (active event resolvable by slug, nonexistent slug 404, draft 404, archived 404, soft-deleted 404, no authentication required, correct payload fields, upload CTA shown/hidden by `upload_enabled`, organizer private data excluded, ready photo count shown). *(Already covered — verify/keep)*

### Requirement 6: Event QR Code Coverage

**User Story:** As a maintainer, I want QR code and public-URL behavior locked by tests, so that organizers get correct shareable links and access boundaries hold.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass QR code coverage in `tests/Feature/EventQrCodeTest.php` (owner receives `publicUrl` prop, `publicUrl` contains the slug and `/e/`, payload includes event and `publicUrl`, non-owner 403, guest redirected to login, `publicUrl` is status-independent for the owner, and public destination parity: active 200 / archived 404 / draft 404 / soft-deleted 404). *(Already covered — verify/keep)*

### Requirement 7: Guest Photo Upload Coverage

**User Story:** As a maintainer, I want guest upload behavior locked by tests, so that guests can upload to active events while malformed, oversized, and unauthorized uploads are rejected.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass guest upload coverage in `tests/Feature/GuestPhotoUploadTest.php` (upload requires no auth; jpg/jpeg/png/webp accepted; unsupported, oversize, too-many, and invalid rejected; upload to an active event persists metadata with `STATUS_PENDING` and asserts the job is queued; draft/archived/soft-deleted 404; upload-disabled 403; client cannot override `event_id`/`status`/path; throttle returns 429). *(Already covered — verify/keep)*

### Requirement 8: Upload Security Coverage

**User Story:** As a maintainer, I want upload security invariants locked by tests, so that server-generated paths and validation cannot be bypassed.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass upload security coverage in `tests/Feature/UploadSecurityTest.php` (server-generated paths with client fields ignored across ~120 iterations; invalid inputs rejected with 422; `.php` payload rejected; CSRF protection on the route; sanitized validation-failure logging). *(Already covered — verify/keep)*

### Requirement 9: Image Processing Coverage

**User Story:** As a maintainer, I want image processing behavior locked by tests, so that originals, optimized, and thumbnail variants are produced correctly and failures are handled safely.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass image processing coverage in `tests/Feature/ImageProcessingTest.php` (upload → synchronous dispatch → original + optimized + thumbnail + ready; large images reduced to ≤ 2048; small images not upscaled; portrait stays portrait; supported formats handled; original bytes unchanged; invalid rejected; more than 20 files rejected; adversarial filenames produce uuid-based paths; processing failure marks the photo failed, cleans up variants, and keeps the original). *(Already covered — verify/keep)*

### Requirement 10: Queued Photo Processing Coverage

**User Story:** As a maintainer, I want queued processing behavior locked by tests, so that background jobs are dispatched, retried, and made idempotent correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass queued processing coverage in `tests/Feature/QueuedPhotoProcessingTest.php` (one job per photo leaving photos pending on upload; no synchronous processing on upload; job moves pending → ready; failure marks failed and cleans up; `tries=3` with backoff `[10,30,60]`; no-op on missing photo; skip already-ready; failed when the original is missing; idempotent with no duplicate rows; multiple photos processed independently; gallery shows only ready photos). *(Already covered — verify/keep)*

### Requirement 11: Photo Status Lifecycle

**User Story:** As a maintainer, I want the photo status lifecycle explicitly documented by a focused test, so that the state progression is unambiguous and only ready photos appear in galleries.

#### Acceptance Criteria

1. WHEN a photo is processed successfully at the job/model level, THE Photo_Lifecycle SHALL progress `pending → processing → ready`. *(New coverage — dedicated lifecycle assertion; references existing queue/gallery tests)*
2. IF processing fails at the job/model level, THEN THE Photo status SHALL transition `processing → failed`. *(New coverage)*
3. THE gallery SHALL make visible only photos whose status is `ready`. *(New coverage — dedicated lifecycle-level assertion; broader gallery visibility is covered by `PhotoGalleryTest`)*

### Requirement 12: Photo Gallery Coverage

**User Story:** As a maintainer, I want gallery behavior locked by tests, so that only ready photos of the correct active event are shown, with safe pagination and payloads.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass gallery coverage in `tests/Feature/PhotoGalleryTest.php` (active gallery requires no auth; invalid slug 404; draft/archived/soft-deleted 404; only ready photos of this event; payload contains only safe fields; pagination of 24 is stable across disjoint pages; empty gallery handled). *(Already covered — verify/keep)*
2. THE Test_Suite SHALL continue to include and pass gallery payload coverage in `tests/Feature/GalleryPayloadTest.php` (thumbnail/optimized URLs for ready photos; processing/failed excluded; legacy fallback to original; processed variants use processed paths). *(Already covered — verify/keep)*

### Requirement 13: Photo Download Coverage

**User Story:** As a maintainer, I want download behavior locked by tests, so that only valid, ready photos of the correct event can be downloaded.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass download coverage in `tests/Feature/PhotoDownloadTest.php` (guest can download a ready photo; nonexistent event 404; archived/draft 404; unknown uuid 404; another event's photo 404; numeric id 404; non-ready 404; missing file 404). *(Already covered — verify/keep)*

### Requirement 14: Event Isolation Coverage

**User Story:** As a maintainer, I want cross-event isolation locked by tests, so that a photo uuid from one event cannot be accessed through another event.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass isolation coverage in `tests/Feature/EventIsolationTest.php` (cross-event photo uuid on download returns 404 across ~100 iterations, with a legitimate 200 control). *(Already covered — verify/keep)*

### Requirement 15: Data Exposure Coverage

**User Story:** As a maintainer, I want data-exposure protections locked by tests, so that internal identifiers, paths, and SQL never leak in responses.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass data-exposure coverage in `tests/Feature/DataExposureTest.php` (no internal ids/paths/uuid/user_id/timestamps in event-page and gallery responses; 404/403 bodies contain no path or SQL details). *(Already covered — verify/keep)*

### Requirement 16: Rate Limiting Coverage

**User Story:** As a maintainer, I want rate limiting locked by tests, so that upload and browse throttles behave correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass rate limiting coverage in `tests/Feature/RateLimitTest.php` (uploads over the limit 429; browse over the limit 429; a multi-file upload counts once; the window resets via time travel). *(Already covered — verify/keep)*

### Requirement 17: Security Headers and Malicious File Coverage

**User Story:** As a maintainer, I want security headers and filesystem protections locked by tests, so that responses are hardened and upload directories cannot execute code.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass security header coverage in `tests/Feature/SecurityHeadersTest.php` (`X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN` on 404 and inertia responses; middleware does not break responses). *(Already covered — verify/keep)*
2. THE Test_Suite SHALL continue to include and pass filesystem protection coverage in `tests/Feature/MaliciousFileProtectionTest.php` (the `.htaccess` file exists with the expected directives). *(Already covered — verify/keep)*

### Requirement 18: Event Photo Cap Coverage

**User Story:** As a maintainer, I want the per-event photo cap locked by tests, so that events cannot exceed their upload limit.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass photo cap coverage in `tests/Feature/EventPhotoCapTest.php` (cap boundary returns 403; soft-deleted photos excluded from the count; sanitized logging). *(Already covered — verify/keep)*

### Requirement 19: Process Photos Command Coverage

**User Story:** As a maintainer, I want the backfill command locked by tests, so that legacy photos are processed safely and idempotently.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass command coverage in `tests/Feature/ProcessPhotosCommandTest.php` (backfill legacy photos to ready; idempotent; skip photos with a missing original; never delete). *(Already covered — verify/keep)*

### Requirement 20: Slug Generator Feature Coverage

**User Story:** As a maintainer, I want slug generation locked by tests at the feature level, so that slugs are well-formed and unique.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass slug coverage in `tests/Feature/SlugGeneratorTest.php` (slug format, uniqueness, incrementing suffixes). *(Already covered — verify/keep)*

### Requirement 21: Settings Coverage

**User Story:** As a maintainer, I want profile and security settings locked by tests, so that account management stays correct.

#### Acceptance Criteria

1. THE Test_Suite SHALL continue to include and pass settings coverage in `tests/Feature/Settings/` (profile and security). *(Already covered — verify/keep)*

### Requirement 22: Model Unit Tests

**User Story:** As a maintainer, I want model behavior verified in isolation, so that relationships, uuid generation, soft deletes, status constants, and URL accessors are individually documented.

#### Acceptance Criteria

1. THE Test_Suite SHALL include Unit_Tests in `tests/Unit` asserting that an `Event` belongs to a `User` and has many `Photo` records, and that a `Photo` belongs to an `Event`. *(New coverage)*
2. WHEN an `Event` or `Photo` is created without an explicit `uuid`, THE model SHALL auto-generate a `uuid`. *(New coverage)*
3. THE Test_Suite SHALL include Unit_Tests asserting `SoftDeletes` behavior for `Event` and `Photo` (soft-deleted rows are excluded from default queries and retained in storage). *(New coverage)*
4. THE Test_Suite SHALL include a Unit_Test asserting the `Photo` status constants `pending`, `processing`, `ready`, `failed`, and `deleted`. *(New coverage)*
5. WHEN `optimized_path` or `thumbnail_path` is null, THE `Photo` accessors `optimizedUrl()` and `thumbnailUrl()` SHALL fall back to the URL derived from `original_path`; WHEN those paths are set, the accessors SHALL use them (asserted with `Storage::fake`). *(New coverage)*
6. THE Test_Suite SHALL include Unit_Tests asserting database-integrity relationships (a `Photo.event_id` references the correct `Event`, an `Event.user_id` references the correct `User`, and no orphan records are created by design). *(New coverage)*

### Requirement 23: Service Unit Tests

**User Story:** As a maintainer, I want the SlugGenerator and PhotoProcessor services verified in isolation, so that their logic is documented independent of HTTP flows.

#### Acceptance Criteria

1. THE Test_Suite SHALL include Unit_Tests for `SlugGenerator` asserting slugification, the empty-name → uuid fallback, and uniqueness/incrementing-suffix logic in isolation. *(New coverage)*
2. WHEN `PhotoProcessor::process` is given a real GD-synthesized original on `Storage::fake('public')`, THE service SHALL return an optimized WebP with maximum dimension ≤ 2048 and a thumbnail WebP with maximum dimension ≤ 500. *(New coverage — reuse GD_Synthesis; do not create new service classes)*
3. WHEN `PhotoProcessor::process` is given an image smaller than the target dimensions, THE service SHALL NOT upscale the image. *(New coverage)*
4. IF `PhotoProcessor::process` is given a non-image input, THEN THE service SHALL throw. *(New coverage)*

### Requirement 24: Factory States

**User Story:** As a maintainer, I want named factory states, so that tests can express photo and event states clearly and consistently.

#### Acceptance Criteria

1. THE PhotoFactory SHALL provide the named states `pending()`, `processing()`, `failed()`, and `ready()`, WHERE `ready()` sets `optimized_path` and `thumbnail_path`. *(New coverage)*
2. THE EventFactory SHALL provide the named states `active()`, `draft()`, and `archived()`. *(New coverage)*
3. WHEN the new factory states are added, THE existing Test_Suite SHALL continue to pass unchanged. *(New coverage — regression guard)*

### Requirement 25: Optional Test Helpers

**User Story:** As a maintainer, I want small reusable test helpers, so that common setup is expressed once without over-engineering.

#### Acceptance Criteria

1. WHERE duplication is meaningfully reduced without reducing readability, THE Test_Suite MAY provide small reusable helpers such as `createOrganizer`, `createActiveEvent`, and `createReadyPhoto`. *(New coverage — optional; MUST NOT mandate a large trait refactor)*

### Requirement 26: Regression and Coverage

**User Story:** As a maintainer, I want the full suite to pass and cover the meaningful business logic, so that Phase 10 leaves a durable, trustworthy regression baseline.

#### Acceptance Criteria

1. WHEN the full Test_Suite is executed via `php artisan test`, THE Test_Suite SHALL pass with no failures. *(New coverage — regression gate)*
2. THE Test_Suite SHALL provide meaningful coverage of business logic, authorization, public endpoints, uploads, image processing, queued processing, and security boundaries. *(New coverage — no arbitrary 100% target)*
3. THE spec SHALL document how to run the suite, including the full run (`php artisan test`) and a filtered run (`php artisan test --filter=...`). *(New coverage — documentation)*

## Out of Scope

The following are explicitly excluded from Phase 10:

- **Frontend / JavaScript tests.** No Vitest, Jest, Playwright, or any JS/TS test toolchain is introduced. React/Inertia/TypeScript components are not tested in this phase.
- **Test folder reorganization.** Existing tests are not moved into subfolders; the flat `tests/Feature/*` and `tests/Unit/*` layout is preserved.
- **New product features.** No new user-facing behavior is added.
- **New tooling / CI / cloud.** No new external services, no cloud dependencies, no CI pipeline changes, no Redis, no Horizon.
- **Production code refactoring.** Production code is changed only to fix a genuine bug discovered by a new test.
- **Framework migration.** No migration away from PHPUnit (e.g., to Pest) and no framework upgrade.
- **`.env.testing` creation.** In-memory SQLite plus array drivers already isolate tests; no separate testing env file is created.
- **Rewriting existing passing tests.** The 161 existing tests are locked, not rewritten.

## Coverage Map (verify-vs-new summary)

| Capability | Existing test file(s) | Status |
|---|---|---|
| Authentication | `Auth/*` | Already covered — verify/keep |
| Dashboard | `DashboardTest` | Already covered — verify/keep |
| Event CRUD + ownership | `EventTest`, `EventAccessSecurityTest` | Already covered — verify/keep |
| Event view happy-path (policy) | `EventTest` | New coverage (assert if absent) |
| Event create/update validation | — | **New coverage** |
| Public event page | `PublicEventPageTest` | Already covered — verify/keep |
| Event QR code / public URL | `EventQrCodeTest` | Already covered — verify/keep |
| Guest photo upload | `GuestPhotoUploadTest` | Already covered — verify/keep |
| Upload security | `UploadSecurityTest` | Already covered — verify/keep |
| Image processing | `ImageProcessingTest` | Already covered — verify/keep |
| Queued processing | `QueuedPhotoProcessingTest` | Already covered — verify/keep |
| Photo status lifecycle | (references queue/gallery tests) | **New coverage** (dedicated lifecycle assertion) |
| Photo gallery | `PhotoGalleryTest`, `GalleryPayloadTest` | Already covered — verify/keep |
| Photo download | `PhotoDownloadTest` | Already covered — verify/keep |
| Event isolation | `EventIsolationTest` | Already covered — verify/keep |
| Data exposure | `DataExposureTest` | Already covered — verify/keep |
| Rate limiting | `RateLimitTest` | Already covered — verify/keep |
| Security headers | `SecurityHeadersTest` | Already covered — verify/keep |
| Malicious file protection | `MaliciousFileProtectionTest` | Already covered — verify/keep |
| Event photo cap | `EventPhotoCapTest` | Already covered — verify/keep |
| Process photos command | `ProcessPhotosCommandTest` | Already covered — verify/keep |
| Slug generator (feature) | `SlugGeneratorTest` | Already covered — verify/keep |
| Settings (profile/security) | `Settings/*` | Already covered — verify/keep |
| Model unit tests (relationships, uuid, soft delete, constants, accessors, integrity) | `Unit/*` (only `ExampleTest` today) | **New coverage** |
| Service unit tests (SlugGenerator, PhotoProcessor) | `Unit/*` | **New coverage** |
| Factory states (Photo, Event) | `database/factories/*` | **New coverage** (test infrastructure) |
| Test helpers (optional) | — | **New coverage** (optional) |
| Regression gate + coverage + run docs | whole suite | **New coverage** |

## Notes

- All "Already covered" requirements are locks: the named files must continue to exist and pass. They are not rewritten.
- All "New coverage" requirements add tests (and permitted factory states/helpers). Production code changes only if a new test reveals a genuine bug.
- Running the suite: full run `php artisan test`; filtered run `php artisan test --filter=EventValidationTest` (or any test/method name).
