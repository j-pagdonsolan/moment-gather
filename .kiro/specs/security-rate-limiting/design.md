# Design Document

## Overview

Phase 9 hardens the public surface of MomentGather (`/e/{slug}` event page, upload, gallery, download) without changing product behavior. The design is governed by one principle: **most of the required security posture already exists and is correct as of Phase 8 — Phase 9 verifies-and-tests it, and only adds the genuine gaps.**

Two kinds of work, kept strictly separate:

- **Verify** — behavior already implemented correctly (event-scoping, IDOR prevention, upload validation, server-generated paths, mass-assignment safety, download/gallery narrowing, job integrity, XSS-safe rendering, CSRF). Phase 9 locks these in with tests and MUST NOT refactor or regress them.
- **New** — the real gaps: a browse rate limiter (R7), an event photo cap (R11), a `SecurityHeaders` middleware (R19), an uploads-area `.htaccess` (R4), a frontend 429 message (R6/R9), and lightweight security logging (R21).

Constraints held throughout: **Laravel-native only.** No Redis, Horizon, Cloudflare, WAF/CDN, CAPTCHA, external auth/security, cloud storage, payments/plans, or AI. Local-only on XAMPP (Apache + MariaDB). Production hardening (separate content domain, `APP_DEBUG=false`, non-executable content serving) is deferred to Phase 14 and only documented here.

Minimal-churn is a hard requirement: new controls are additive (new config keys, one new limiter, one new middleware, one guard clause, one FormRequest hook, one tracked file, one frontend branch). No existing method signature, route handler, model, or migration changes shape.

## Architecture

The public request pipeline, with each control annotated by requirement. New controls are marked **[NEW]**; everything else is **[VERIFY]**.

```
Guest request  →  /e/{slug}...
        │
        ▼
┌───────────────────────────────────────────────────────────────┐
│ web middleware group (bootstrap/app.php)                        │
│   • CSRF (VerifyCsrfToken) ...................... R17 [VERIFY]   │
│   • HandleAppearance / HandleInertiaRequests .... existing      │
│   • AddLinkHeadersForPreloadedAssets ............ existing      │
│   • SecurityHeaders (append) .................... R19 [NEW]     │
└───────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────┐
│ route middleware (routes/web.php)                               │
│   • POST /e/{slug}/photos      → throttle:uploads  R6 [VERIFY]  │
│   • GET  /e/{slug}             → throttle:browse   R7 [NEW]     │
│   • GET  /e/{slug}/gallery     → throttle:browse   R7 [NEW]     │
│   • GET  /e/{slug}/.../download→ throttle:browse   R7 [NEW]     │
│   Limiters keyed by client IP (AppServiceProvider) R8 [NEW/VER]│
└───────────────────────────────────────────────────────────────┘
        │
        ▼  (upload path shown; browse paths go straight to controller)
┌───────────────────────────────────────────────────────────────┐
│ StorePhotosRequest (FormRequest)                                │
│   • photos array + size/count/mime/image rules .. R3,R10 [VER]  │
│   • failedValidation() → Log::warning (sanitized) R21 [NEW]     │
│   • 422 on failure (parent behavior preserved) .. R3 [VERIFY]   │
└───────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────┐
│ PublicPhotoUploadController@store                               │
│   • resolve Active_Event (slug + status=active) . R1 [VERIFY]   │
│   • abort_if !upload_enabled → 403 .............. existing      │
│   • event photo cap guard → 403 + Log::warning .. R11,R21 [NEW] │
│   • server-generated path events/{uuid}/... .... R5,R16 [VER]   │
│   • Photo::create server-side values only ....... R16 [VERIFY]  │
│   • ProcessPhoto::dispatch(id) one per photo .... R12 [VERIFY]  │
└───────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────┐
│ Storage: public disk (storage/app/public → public/storage)     │
│   • .htaccess disables PHP handler .............. R4 [NEW]      │
│   • Apache serves originals/variants as static .. R4 [NEW]      │
└───────────────────────────────────────────────────────────────┘
        │
        ▼
   ProcessPhoto job (queue) — idempotent, retry/backoff, cleanup  R12 [VERIFY]

Read paths (gallery/download/event) resolve Active_Event, scope photos
to the event relationship, ready-only, and emit narrow payloads.
                                            R2,R13,R14,R15 [VERIFY]

Frontend PhotoUploader.tsx: renders error bag as text (XSS-safe R18),
and on HTTP 429 shows Rate_Limit_Exceeded_Message.   R6,R9 [NEW]
```

The defense layers are independent and ordered so the cheapest checks run first (throttle before validation before DB count before storage). No single layer is trusted alone — event-scoping, server-generated paths, and mass-assignment safety together prevent IDOR and path influence even if one is bypassed.

## Components and Interfaces

Each change lists its file, classification (**Verify** = tests only, no code change; **New** = additive code), the requirement it traces to, and the exact intended shape.

### 1. `config/uploads.php` — **New** (R7.5, R11.1)

Add two keys alongside the existing three. No existing key changes.

```php
return [
    'rate_limit'        => (int) env('UPLOAD_RATE_LIMIT', 10),        // existing
    'max_files'         => (int) env('UPLOAD_MAX_FILES', 20),         // existing
    'max_file_kb'       => (int) env('UPLOAD_MAX_FILE_KB', 20480),    // existing
    'browse_rate_limit' => (int) env('BROWSE_RATE_LIMIT', 60),        // NEW — R7
    'max_per_event'     => (int) env('UPLOAD_MAX_PER_EVENT', 500),    // NEW — R11
];
```

`.env.example` gains `BROWSE_RATE_LIMIT=60` and `UPLOAD_MAX_PER_EVENT=500` for discoverability (documentation only).

### 2. `app/Providers/AppServiceProvider.php` — **New** (R7.1, R8.1)

Register a `browse` limiter next to the existing `uploads` limiter in `boot()`. Keyed by client IP, config-driven, more permissive than uploads.

```php
RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(
    (int) config('uploads.rate_limit', 10)
)->by($request->ip()));                                              // existing — R6/R8

RateLimiter::for('browse', fn (Request $request) => Limit::perMinute(  // NEW — R7/R8
    (int) config('uploads.browse_rate_limit', 60)
)->by($request->ip()));
```

### 3. `routes/web.php` — **New** (R7.2)

Add `->middleware('throttle:browse')` to the three Browse_Endpoints. The upload route keeps **only** `throttle:uploads` (it is intentionally *not* also placed under `browse`: uploads have their own stricter limit and adding browse would double-count and confuse the 429 semantics).

```php
Route::get('/e/{slug}', [PublicEventController::class, 'show'])
    ->middleware('throttle:browse')                 // NEW
    ->name('public.events.show');

Route::post('/e/{slug}/photos', [PublicPhotoUploadController::class, 'store'])
    ->middleware('throttle:uploads')                // unchanged — uploads only
    ->name('public.events.photos.store');

Route::get('/e/{slug}/gallery', [PublicGalleryController::class, 'show'])
    ->middleware('throttle:browse')                 // NEW
    ->name('public.events.gallery');

Route::get('/e/{slug}/photos/{photo}/download', [PublicPhotoDownloadController::class, 'show'])
    ->middleware('throttle:browse')                 // NEW
    ->name('public.events.photos.download');
```

### 4. `app/Http/Middleware/SecurityHeaders.php` — **New** (R19)

New middleware, registered by appending to the `web` group in `bootstrap/app.php`. It sets exactly two headers and nothing else — deliberately no CSP — so Inertia navigation, Vite dev assets, and local image/asset loading keep working (R19.4).

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');   // R19.2
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');       // R19.3

        return $response;
    }
}
```

Registration in `bootstrap/app.php` (append after the existing three, so it runs on the response of every web route including public and download):

```php
$middleware->web(append: [
    HandleAppearance::class,
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
    SecurityHeaders::class,                          // NEW — R19
]);
```

### 5. `app/Http/Controllers/PublicPhotoUploadController.php` — **New** (R11.2–R11.4, R21.2)

Add an event photo-cap guard **after** the `upload_enabled` check and **before** the storage/dispatch loop. Validation has already run (FormRequest), so `$request->file('photos')` is a validated array. Everything else in the method stays byte-for-byte intact.

Placement and logic:

```php
abort_if(! $event->upload_enabled, 403, 'Photo uploads are currently closed.');

// NEW — R11: event-level photo cap (abuse safeguard, not billing).
$incoming = count($request->file('photos'));
$currentCount = $event->photos()->count();   // SoftDeletes global scope excludes
                                             // soft-deleted → counts pending+processing+ready
$maxPerEvent = (int) config('uploads.max_per_event', 500);

if ($currentCount + $incoming > $maxPerEvent) {
    Log::warning('Upload rejected: event photo cap exceeded', [   // R21.2 — sanitized
        'event_slug'    => $event->slug,
        'current_count' => $currentCount,
        'incoming'      => $incoming,
        'max_per_event' => $maxPerEvent,
    ]);

    abort(403, 'This event has reached its photo limit. Please contact the organizer.');
}

$disk = Storage::disk('public');
foreach ($request->file('photos') as $file) { /* unchanged */ }
```

Notes:
- `$event->photos()->count()` relies on the `SoftDeletes` global scope, which appends `deleted_at is null`. So the count is exactly the Non_Deleted_Photo count (pending + processing + ready) as R11 requires — no extra `whereIn('status', ...)` needed.
- The check is a single `COUNT(*)` query; no new column, no schema change (see Data Models).
- Requires adding `use Illuminate\Support\Facades\Log;` to the controller imports.

### 6. `app/Http/Requests/StorePhotosRequest.php` — **New** (R21.1) + **Verify** (R3, R10)

**Verify** the existing rules unchanged: `photos required|array|max:max_files`; `photos.* required|file|image|mimes:jpg,jpeg,png,webp|max:max_file_kb`. This already satisfies R3.1–R3.6 and R10.1–R10.3 and rejects `.php` payloads (fail `image`/`mimes`) per R4.1 and R22.9.

**New** — override `failedValidation()` to log a sanitized warning, then defer to the parent so the default 422 + error-bag behavior is untouched (R3.5). The simplest approach, no listener/event wiring:

```php
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Log;

protected function failedValidation(Validator $validator): void
{
    Log::warning('Upload validation failed', [                    // R21.1 — sanitized
        'event_slug'  => $this->route('slug'),
        'file_count'  => is_array($this->file('photos')) ? count($this->file('photos')) : 0,
        'first_error' => array_key_first($validator->errors()->toArray()),
    ]);

    parent::failedValidation($validator);   // preserves HTTP 422 + error bag
}
```

Logged fields are safe by construction: no file contents, no filenames, no PII beyond the IP Laravel already captures in its request log (R21.4). `file_count` and `first_error` (a rule key like `photos.0`) are non-sensitive.

### 7. Uploads-area `.htaccess` — **New** (R4.2–R4.4)

Disable Apache PHP execution inside the Upload_Storage_Area so a file that slips past image validation still cannot run as a script (defense-in-depth on top of R3/R4.1 validation).

- **Location:** `storage/app/public/.htaccess` (the `public` disk root, symlinked to `public/storage` and Apache-served).
- **Contents** (covers mod_php via `engine off`, and handler/type mappings via `FilesMatch` + `RemoveHandler`/`RemoveType`, for Apache 2.4):

```apacheconf
# Disable server-side script execution for user-uploaded content.
php_flag engine off

<FilesMatch "\.(php|phtml|php3|php4|php5|php7|phar|pl|py|cgi|asp|aspx|sh|shtml)$">
    Require all denied
</FilesMatch>

RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar
```

- **Tracking / determinism:** `storage/app/public` is normally gitignored except its own `.gitignore`. To guarantee the file exists deterministically after clone, it is a **tracked file**, force-added to git (`git add -f storage/app/public/.htaccess`) during the implementation task. The design assumption is documented: the `.htaccess` is version-controlled and must be present in the uploads area. (An artisan/deploy step is not introduced — a tracked file is the pragmatic, zero-runtime-cost choice for a local XAMPP app.)
- **Production note (R4.4):** production (Phase 14) will serve user content from a non-executable location or separate domain; the `.htaccess` is the local defense-in-depth measure and is documented as such.
- Because `php_flag`/`RemoveHandler` under `<Directory>`-style directives require Apache to honor `.htaccess` (`AllowOverride`), the design notes XAMPP's default `AllowOverride All` for `htdocs` satisfies this locally.

### 8. `resources/js/components/PhotoUploader.tsx` — **New** (R6.5, R9.2)

Add HTTP 429 handling to the existing `useForm().post` call, rendered consistently with the existing error block (plain text, XSS-safe). Introduce one piece of state and inspect the error in `onError`.

```tsx
const [rateLimited, setRateLimited] = useState(false);

function submit() {
    setRateLimited(false);
    post(`/e/${slug}/photos`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { /* unchanged */ },
        onError: (errs) => {
            // Inertia surfaces a 429 as a page error / status; detect and show copy.
            if ((errs as Record<string, unknown>)?.status === 429 /* or Inertia 429 signal */) {
                setRateLimited(true);
            }
        },
    });
}
```

Rendering (near the existing `errorMessages` block, same styling):

```tsx
{rateLimited && (
    <div className="text-center text-sm text-destructive">
        <p>You've uploaded too many photos in a short period. Please wait a moment and try again.</p>
    </div>
)}
```

Implementation note for the tasks phase: Inertia's exact 429 surfacing (via `onError` payload vs. a global `router.on('invalid')`/response-status check) is confirmed against the installed `@inertiajs/react` version during implementation; the design fixes the **behavior** (detect 429 → render the exact `Rate_Limit_Exceeded_Message`) and the copy string, not the precise detection hook. The message text is verbatim from the glossary.

### Verify-only files (tests + invariants, no code change)

| File | Requirement | Invariant to preserve |
|------|-------------|-----------------------|
| `PublicEventController@show` | R1, R15 | Resolves via `slug + status=active` + SoftDeletes 404; payload exposes only `slug, name, description, event_date, location, upload_enabled, photoCount` — never `id, uuid, user_id, timestamps, paths`. |
| `PublicGalleryController@show` | R2, R14 | Resolves Active_Event; photos via `$event->photos()->where('status', ready)`; per-photo payload only `uuid, thumbnailUrl, optimizedUrl, filename, width, height`; URLs via `Storage::url`; no raw path, no db id. |
| `PublicPhotoDownloadController@show` | R2, R13 | Active_Event; `$event->photos()->where('uuid',$photo)->where('status',ready)->firstOrFail()` (event-scoped, IDOR-safe); `abort_unless(exists,404)`; `Storage::download` (no client path). |
| `PublicPhotoUploadController@store` (existing parts) | R5, R16 | Server-generated `events/{uuid}/originals/{uuid}.{ext}`; `Photo::create` from server values with `status = STATUS_PENDING`; client `event_id/status/original_path` ignored. |
| `Photo` model | R16 | `#[Fillable]` present but controller supplies explicit server array; `creating` hook backfills uuid. |
| `Event` model | R1 | `getRouteKeyName = uuid` (public routes use `{slug}` string param, not model binding); `SoftDeletes`. |
| `ProcessPhoto` job | R12, R21.3 | `$tries=3`, `backoff [10,30,60]`; no-op if photo missing; skip if ready+both variants; missing original → failed; `failed()` cleans variants + `Log::error`. |
| `Public/Event.tsx`, `Public/Gallery.tsx` | R18 | Render event/photo text via React default escaping; no `dangerouslySetInnerHTML` for public content. |
| CSRF (web group) | R17 | Upload route stays in `web` group; not excluded from CSRF; client sends `X-XSRF-TOKEN`. |

## Data Models

**No schema or migration changes.** Phase 9 introduces no new columns, tables, or indexes.

- The event photo cap (R11) is computed at request time as a single `COUNT(*)` over the existing `photos` relationship: `$event->photos()->count()`. The `SoftDeletes` global scope already restricts this to non-deleted rows, which equals the Non_Deleted_Photo set (pending + processing + ready). No stored counter, no denormalized column — the count is always derived, so it cannot drift.
- Config values (`browse_rate_limit`, `max_per_event`) live in `config/uploads.php` backed by env; they are not persisted data.
- Rate-limit state is held by Laravel's cache-backed limiter (the existing default cache store), not a database model.

Existing models are unchanged: `Event` (`uuid, name, slug, description, event_date, location, status, upload_enabled` + SoftDeletes), `Photo` (`event_id, uuid, original_filename, original_path, optimized_path, thumbnail_path, mime_type, file_size, width, height, status` + SoftDeletes with the five status constants).

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

The prework analysis classified each acceptance criterion as PROPERTY, EXAMPLE, EDGE_CASE, INTEGRATION, or SMOKE. Only the PROPERTY-classified criteria appear below, consolidated per the reflection to remove redundancy. EXAMPLE/SMOKE items (security headers, CSRF, logging sanitization, `.htaccess` presence, `.php` payload) are covered in the Testing Strategy as focused example/smoke tests.

### Property 1: Only active events are reachable

*For any* event whose status is not `active` (e.g. `draft`, `archived`) or which is soft-deleted, and *for any* slug that matches no event, every Public_Endpoint request for that slug SHALL return HTTP 404; and for any active, non-deleted event the endpoint SHALL resolve it.

**Validates: Requirements 1.1, 1.2, 1.3, 1.4, 13.1, 14.1**

### Property 2: Photos are reachable only through their own event (IDOR)

*For any* two distinct active events A and B and *for any* ready photo belonging to B, requesting that photo's `uuid` on the Download_Endpoint under A's slug SHALL return HTTP 404. Photo resolution is always scoped through the event relationship, never a global `Photo` query.

**Validates: Requirements 2.1, 2.3, 2.4, 13.2, 13.3**

### Property 3: Public responses expose no internal identifiers or paths

*For any* event and *for any* set of its photos, every Public_Endpoint response SHALL contain only the permitted display fields and SHALL NOT contain any raw storage path segment (e.g. `events/…/originals`), database primary/foreign key, event `uuid`, `user_id`, or internal timestamp.

**Validates: Requirements 2.5, 13.5, 14.3, 14.4, 15.1, 15.2, 15.3, 20.2**

### Property 4: Stored paths are server-generated and client fields are ignored

*For any* uploaded file — including one whose client-supplied filename contains path-traversal sequences such as `../` — and *for any* injected client `event_id`, `status`, or `original_path` values, the persisted photo SHALL have `original_path` matching `events/{event_uuid}/originals/{uuid}.{ext}` within the resolved event's storage area, `event_id` equal to the resolved event, and `status` equal to the server-set pending state.

**Validates: Requirements 5.1, 5.2, 5.3, 5.4, 16.1, 16.2, 16.3**

### Property 5: The event photo cap rejects exactly at the boundary

*For any* event with C non-deleted photos, *for any* upload of N files, and *for any* configured cap M, the Upload_Endpoint SHALL respond with HTTP 403 (storing no photos and dispatching no jobs) if and only if C + N > M, and otherwise accept and persist the photos; soft-deleted photos SHALL NOT count toward C.

**Validates: Requirements 11.2, 11.3, 11.4**

### Property 6: Requests over the rate limit are rejected

*For any* rate-limited endpoint keyed by client IP with per-minute limit L (L = Upload_Rate_Limit for the Upload_Endpoint, L = Browse_Rate_Limit for a Browse_Endpoint), the first L requests within the window from one IP SHALL succeed and request number L+1 SHALL return HTTP 429; a single upload request carrying up to Max_Photos_Per_Request files SHALL count as exactly one request against the limit.

**Validates: Requirements 6.2, 6.3, 6.4, 7.1, 7.4, 8.1, 9.1**

### Property 7: Invalid uploads are rejected server-side

*For any* upload where a `photos.*` item is not a Supported_Format, or exceeds Max_Photo_Size, or where the `photos` array exceeds Max_Photos_Per_Request items, the StorePhotosRequest SHALL reject the request with HTTP 422 and SHALL persist no photo. In particular, a file with a script extension/content (e.g. `.php`) SHALL be rejected.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 4.1, 10.1, 10.2, 10.3**

### Property 8: Photo processing is idempotent

*For any* ready photo that already has both `optimized_path` and `thumbnail_path`, running the ProcessPhoto job again SHALL leave the photo's state unchanged (no reprocessing, no additional variants, no status change).

**Validates: Requirements 12.2, 12.3**

## Error Handling

- **Status codes are generic and correct** (R20.1): 404 for non-active/soft-deleted/nonexistent events and out-of-scope or cross-event photos (via `firstOrFail` + `abort_unless(...,404)`); 403 for uploads-closed and the event photo cap; 429 for exceeded rate limits (uploads and browse); 422 for validation failures.
- **No internal leakage** (R20.2, R9.3): error responses carry no stack traces, storage paths, or SQL. `firstOrFail` yields a plain 404; the cap and closed-upload aborts use friendly guest-facing copy only ("This event has reached its photo limit. Please contact the organizer." / "Photo uploads are currently closed."); the 429 body is Laravel's default and is presented to the guest through the friendly `Rate_Limit_Exceeded_Message` in the UI.
- **Friendly guest copy** is surfaced in the UI: validation errors already render from the error bag as text; the 429 renders the exact `Rate_Limit_Exceeded_Message`.
- **APP_DEBUG** (R20.3): documented that `APP_DEBUG` MUST be `false` in production (Phase 14) so framework error detail is never shown to guests; it may remain `true` locally. Phase 9 does not change the value — it records the requirement.

## Testing Strategy

**Dual approach.** Property-based tests cover the universal security invariants (Properties 1–8) across generated inputs; example/smoke tests cover fixed-shape or configuration concerns (headers, CSRF, logging, `.htaccess`, the `.php` payload). Both are necessary; property tests catch edge cases (adversarial filenames, boundary counts, status permutations) that hand-picked examples miss.

**Property-based testing library.** The suite is Pest/PHPUnit (`tests/Feature`). Use a PHP property-based testing library rather than hand-rolling generators — the recommended choice is **`innmind/black-box`** (Pest-compatible, actively maintained) or **`giorgiosironi/eris`**; the tasks phase pins one in `composer.json` (dev). Each property test:
- runs a **minimum of 100 iterations**,
- is tagged with a comment: `// Feature: security-rate-limiting, Property {n}: {property_text}`,
- fakes storage (`Storage::fake('public')`) and the queue (`Queue::fake()`) so iterations are cheap and side-effect-free,
- and maps to exactly one design property.

**Avoid duplication with Phase 1–8 tests.** Many "verify" assertions already exist. The strategy references them and adds only what is missing:

| Existing test file | Already covers | Phase 9 action |
|--------------------|----------------|----------------|
| `GuestPhotoUploadTest` | upload happy path, `upload_enabled=false` 403, server-generated path, `Photo::create` server values | Reference for Properties 4/7; add cap (P5) + `.php` payload + mass-assignment injection cases |
| `ImageProcessingTest`, `QueuedPhotoProcessingTest` | job success, missing photo/original, retries, cleanup | Reference for Property 8 (idempotence) + R12 verify; add idempotence iteration if absent |
| `PhotoDownloadTest` | ready-only, missing file 404 | Add cross-event IDOR case (Property 2) if absent |
| `PhotoGalleryTest`, `GalleryPayloadTest` | ready-only scoping, narrow payload | Reference for Properties 1/3 |
| `PublicEventPageTest` | active resolution, narrow payload | Add draft/archived/soft-deleted/nonexistent 404 matrix (Property 1) + no-exposure (Property 3) |

**New test files** (focused, one concern each — mapping to Requirement 22's matrix):

- `EventAccessSecurityTest` — Property 1 (active resolves; draft/archived/soft-deleted/nonexistent → 404). (R22.1)
- `EventIsolationTest` — Property 2 (cross-event photo uuid → 404). (R22.2)
- `UploadSecurityTest` — Property 4 (path traversal + mass assignment), Property 7 (unsupported/oversized/over-count/`.php` → 422), and the sanitized validation-failure log. (R22.3, R22.7, R22.8, R22.9)
- `EventPhotoCapTest` — Property 5 (C+N>M → 403; soft-deleted excluded; cap log warning). (R22.11)
- `RateLimitTest` — Property 6 (uploads and browse over-limit → 429; multi-file counts once; window recovery via `$this->travel()->minutes(1)` — never real sleeping; reset limiter state between cases with `RateLimiter::clear` or a fresh cache store). (R22.4, R22.5)
- `DataExposureTest` — Property 3 (no raw paths/ids in any Public_Endpoint response; 404/403/429 bodies carry no path/SQL). (R22.10)
- `SecurityHeadersTest` — example: `X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN` present on representative web responses; Inertia page + asset/image response still 200. (R22.12)
- `MaliciousFileProtectionTest` — smoke: assert tracked `storage/app/public/.htaccess` exists and contains the engine-off / `FilesMatch` directives (actual Apache non-execution is a documented manual check). (R4.2, R4.3)
- CSRF example: tokenless cross-site POST to the upload route is rejected (in `UploadSecurityTest` or a small `CsrfTest`). (R17)

**Regression coverage** (R22.13): the full existing Phase 1–8 suite runs unchanged in CI (`.github/workflows/tests.yml`); Phase 9 changes must not break it. Because all Phase 9 backend changes are additive guard clauses / middleware / config, existing tests should pass without modification — any failure signals an unintended regression.

**Frontend 429.** A component-level check (or a feature test asserting the server 429) confirms the uploader shows `Rate_Limit_Exceeded_Message` on a 429; the exact Inertia detection hook is finalized against the installed `@inertiajs/react` version during implementation.

## Design Decisions and Rationale

- **Cap = 500, safeguard not billing** (R11.5): 500 non-deleted photos per event is a generous abuse ceiling for a local event-sharing app, not a plan/quota. It is env-configurable (`UPLOAD_MAX_PER_EVENT`) and computed live from `photos()->count()` so it never drifts and needs no schema change. Explicitly not tied to payments (out of scope).
- **Browse = 60/min, more permissive than uploads (10/min)** (R7.3): normal guests page through a gallery and load images far faster than they upload; 60/min per IP curbs scraping/abuse without disrupting real browsing. Both limits are config-driven and IP-keyed.
- **Two headers only, no CSP** (R19): `X-Content-Type-Options: nosniff` + `X-Frame-Options: SAMEORIGIN` mitigate MIME-sniffing and clickjacking with zero risk to Inertia navigation, Vite dev asset loading, or local image/`<script>`/`<style>` serving. A restrictive CSP would break Vite HMR and inline styles and is deferred to Phase 14.
- **`.htaccess` as a tracked file** (R4): the uploads area is normally gitignored, so a runtime step could silently no-op. Force-adding the `.htaccess` to version control makes its presence deterministic after clone with no runtime cost — the pragmatic choice for local XAMPP. It is defense-in-depth layered on top of image validation; production non-executable serving is Phase 14.
- **IP-based keying** (R8): uses `$request->ip()` for guests — sufficient for abuse control, no fingerprinting, no PII collection beyond the IP Laravel already logs.
- **Logging sanitization** (R21): warnings on validation failure and cap rejection log only counts, the event slug (already in the public URL), and an error-rule key — never file contents, filenames, or PII. Uses `Log::warning` at notice/warning level; the job's existing `Log::error` is untouched.
- **Uploads route stays on `throttle:uploads` only** (R6): not also `throttle:browse`, to keep 429 semantics unambiguous and avoid double-counting a single upload against two limiters.
- **Additive-only backend changes**: every new control is a config key, a limiter, a middleware, one guard clause, or one FormRequest hook — no existing signature or query changes, satisfying the minimal-churn mandate.

## Security Invariants (property summary)

The security posture reduces to these invariants, each backed by a correctness property above and a test:

1. **Active-only resolution** — non-active, soft-deleted, and unknown events are unreachable (Property 1).
2. **Event-scoping / IDOR** — a photo is reachable only through its own event (Property 2).
3. **No-path-exposure** — no response leaks storage paths or internal ids (Property 3).
4. **Filename-safety + mass-assignment** — stored paths and ownership are server-determined; client input is ignored (Property 4).
5. **Cap** — non-deleted photos per event never exceed the configured maximum (Property 5).
6. **Rate-limit** — over-limit requests per IP are rejected with 429 for both uploads and browse (Property 6).
7. **Validation** — only supported, in-limit images are accepted; scripts are rejected (Property 7).
8. **Job idempotence** — reprocessing a completed photo is a no-op (Property 8).
