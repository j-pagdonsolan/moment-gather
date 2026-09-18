# Requirements Document

## Introduction

MomentGather Phase 9 hardens the public-facing surface of the application (guest event page, photo upload, gallery, and download) without changing core product behavior. The application is a Laravel 13 + React 19 + Inertia v3 + TypeScript event photo-sharing app running local-only on XAMPP (Apache + MariaDB). Phases 1–8 are complete.

Much of the required security posture already exists and is correct as of Phase 8. This document therefore separates two kinds of work:

- **(Verify existing behavior)** — Behavior that is already implemented correctly. Phase 9 must lock it in with tests and MUST NOT regress or refactor it.
- **(New work)** — Genuine gaps that Phase 9 introduces.

All work stays Laravel-native. No Redis, Horizon, Cloudflare, AWS/WAF, CAPTCHA, external auth/security services, cloud storage, payments, subscriptions, or AI are introduced. The application remains local-only; production hardening is deferred to Phase 14.

Each requirement is tagged **(Verify existing behavior)** or **(New work)** in its heading so design and tasks can minimize churn.

## Glossary

- **Public_Endpoint**: Any unauthenticated route under the `/e/{slug}` prefix — the event page (`GET /e/{slug}`), the upload endpoint, the gallery (`GET /e/{slug}/gallery`), and the download endpoint.
- **Upload_Endpoint**: `POST /e/{slug}/photos` handled by `PublicPhotoUploadController@store`.
- **Download_Endpoint**: `GET /e/{slug}/photos/{photo}/download` handled by `PublicPhotoDownloadController@show`.
- **Browse_Endpoints**: The read-only Public_Endpoints — event page, gallery, and download.
- **Active_Event**: An `Event` record with `status` equal to `active` and not soft-deleted, resolvable by its `slug`.
- **Event_Owner**: The authenticated organizer who created an `Event`.
- **Guest**: An unauthenticated visitor interacting with a Public_Endpoint.
- **Supported_Format**: An image file with extension and MIME of one of: `jpg`, `jpeg`, `png`, `webp`.
- **Max_Photo_Size**: The maximum accepted size per uploaded file, 20 MB (`uploads.max_file_kb`, default 20480 KB, env `UPLOAD_MAX_FILE_KB`).
- **Max_Photos_Per_Request**: The maximum number of files accepted in one upload request, 20 (`uploads.max_files`, env `UPLOAD_MAX_FILES`).
- **Max_Photos_Per_Event**: The maximum number of non-deleted photos allowed per event, 500 (`uploads.max_per_event`, default 500, env `UPLOAD_MAX_PER_EVENT`).
- **Upload_Rate_Limit**: The maximum upload requests per minute per client IP, 10 (`uploads.rate_limit`, default 10, env `UPLOAD_RATE_LIMIT`).
- **Browse_Rate_Limit**: The maximum requests per minute per client IP for Browse_Endpoints, 60 (config-driven).
- **Server_Generated_Filename**: A storage path built entirely from server-side values (`events/{event_uuid}/originals/{uuid}.{ext}`), never from client-supplied names.
- **Non_Deleted_Photo**: A `Photo` whose `status` is one of `pending`, `processing`, or `ready` and which is not soft-deleted.
- **Ready_Photo**: A `Photo` with `status` equal to `ready`.
- **Upload_Storage_Area**: The Laravel `public` disk root (`storage/app/public`), symlinked to `public/storage` and served by Apache, where originals and variants live.
- **Security_Headers_Middleware**: A new middleware that appends security response headers to web responses.
- **Rate_Limit_Exceeded_Message**: The friendly guest-facing copy shown on a `429` upload response: "You've uploaded too many photos in a short period. Please wait a moment and try again."

## Requirements

### Requirement 1: Public Endpoint Access Control (Verify existing behavior)

**User Story:** As an Event_Owner, I want only active events to be reachable through public links, so that draft, archived, or deleted events are never exposed to guests.

#### Acceptance Criteria

1. WHEN a Guest requests a Public_Endpoint for a slug matching an Active_Event, THE PublicEventController SHALL resolve the event and serve the requested resource.
2. IF a Guest requests a Public_Endpoint for a slug whose event has `status` of `draft` or `archived`, THEN THE PublicEventController SHALL respond with HTTP 404.
3. IF a Guest requests a Public_Endpoint for a slug whose event is soft-deleted, THEN THE PublicEventController SHALL respond with HTTP 404.
4. IF a Guest requests a Public_Endpoint for a slug that matches no event, THEN THE PublicEventController SHALL respond with HTTP 404.
5. THE PublicEventController, PublicGalleryController, and PublicPhotoDownloadController SHALL resolve the event using `where('slug', $slug)->where('status', 'active')->firstOrFail()`.

### Requirement 2: Event Isolation and IDOR Prevention (Verify existing behavior)

**User Story:** As an Event_Owner, I want photos accessible only through their own event, so that a guest cannot reach another event's photos by guessing identifiers.

#### Acceptance Criteria

1. WHEN the Download_Endpoint resolves a photo, THE PublicPhotoDownloadController SHALL resolve it via the event relationship using `$event->photos()->where('uuid', $photo)->where('status', 'ready')->firstOrFail()`.
2. WHEN the gallery is served, THE PublicGalleryController SHALL resolve photos via `$event->photos()->where('status', 'ready')`.
3. THE PublicPhotoDownloadController and PublicGalleryController SHALL NOT resolve photos through a global `Photo` query unscoped by event.
4. IF a Guest requests a photo `uuid` that belongs to a different event than the slug in the URL, THEN THE PublicPhotoDownloadController SHALL respond with HTTP 404.
5. THE Public_Endpoints SHALL NOT include any other event's identifiers, photo identifiers, storage paths, or database columns in any response.

### Requirement 3: Upload Validation (Verify existing behavior)

**User Story:** As an Event_Owner, I want uploads validated server-side, so that only supported image files within limits are accepted regardless of client behavior.

#### Acceptance Criteria

1. WHEN an upload request is received, THE StorePhotosRequest SHALL require `photos` to be an array with at most Max_Photos_Per_Request items.
2. WHEN an upload request is received, THE StorePhotosRequest SHALL require each `photos.*` item to be a file that passes the `image` rule.
3. WHEN an upload request is received, THE StorePhotosRequest SHALL require each `photos.*` item to have a MIME type and extension in Supported_Format.
4. WHEN an upload request is received, THE StorePhotosRequest SHALL reject any `photos.*` item exceeding Max_Photo_Size.
5. IF any `photos.*` item fails validation, THEN THE StorePhotosRequest SHALL reject the request with HTTP 422 and validation errors.
6. THE StorePhotosRequest SHALL enforce all upload constraints on the server regardless of client-side checks.

### Requirement 4: Malicious File Protection (New work — .htaccess; Verify existing — validation)

**User Story:** As an Event_Owner, I want uploaded files to be unable to execute as server-side scripts, so that a file that passes image validation cannot be used to run code.

#### Acceptance Criteria

1. WHEN a Guest uploads a file whose content or extension is not a Supported_Format, THE StorePhotosRequest SHALL reject the request with HTTP 422. (Verify existing behavior)
2. THE application SHALL place an `.htaccess` file in the Upload_Storage_Area that disables the PHP handler for files served from that location. (New work)
3. WHILE the `.htaccess` protection is present, IF a request targets a file within the Upload_Storage_Area, THEN Apache SHALL serve the file as static content and SHALL NOT execute it as PHP. (New work)
4. THE requirements SHALL document the assumption that production (Phase 14) serves user content from a non-executable location or a separate domain. (New work)

### Requirement 5: Filename Security and Path Traversal Prevention (Verify existing behavior)

**User Story:** As an Event_Owner, I want stored file paths built entirely by the server, so that a guest cannot influence storage location through a crafted filename.

#### Acceptance Criteria

1. WHEN a photo is stored, THE PublicPhotoUploadController SHALL use a Server_Generated_Filename of the form `events/{event_uuid}/originals/{uuid}.{ext}`.
2. THE PublicPhotoUploadController SHALL NOT use any client-supplied filename as part of a storage path.
3. THE PublicPhotoUploadController SHALL NOT concatenate untrusted input into storage paths.
4. IF a client-supplied filename contains path traversal sequences such as `../`, THEN THE stored path SHALL remain within the event's Upload_Storage_Area and SHALL be unaffected by the supplied name.

### Requirement 6: Upload Rate Limiting (Verify existing behavior — limiter; New work — frontend message)

**User Story:** As an Event_Owner, I want uploads limited per IP, so that a single guest cannot flood the system while normal multi-photo uploads still work.

#### Acceptance Criteria

1. THE Upload_Endpoint SHALL apply the `throttle:uploads` middleware.
2. THE `uploads` rate limiter SHALL permit Upload_Rate_Limit requests per minute keyed by the client IP.
3. WHEN a Guest submits one request containing multiple photos within Max_Photos_Per_Request, THE Upload_Endpoint SHALL count the submission as a single request against Upload_Rate_Limit.
4. IF a Guest exceeds Upload_Rate_Limit within one minute, THEN THE Upload_Endpoint SHALL respond with HTTP 429.
5. WHEN the upload response is HTTP 429, THE public upload UI SHALL display the Rate_Limit_Exceeded_Message. (New work)

### Requirement 7: Public Browsing Rate Limiting (New work)

**User Story:** As an Event_Owner, I want lenient rate limits on browsing endpoints, so that abusive traffic is curbed without disrupting normal guests.

#### Acceptance Criteria

1. THE application SHALL define a named rate limiter for Browse_Endpoints permitting Browse_Rate_Limit requests per minute keyed by the client IP.
2. THE event page, gallery, and Download_Endpoint SHALL apply the Browse_Endpoints throttle middleware.
3. THE Browse_Rate_Limit SHALL be more permissive than the Upload_Rate_Limit.
4. IF a Guest exceeds the Browse_Rate_Limit within one minute, THEN THE affected Browse_Endpoint SHALL respond with HTTP 429.
5. THE Browse_Rate_Limit SHALL be configurable through application config.

### Requirement 8: Rate-Limit Identification (New work — encode policy)

**User Story:** As an Event_Owner, I want rate limits keyed by IP for guests, so that limiting works without collecting invasive personal data.

#### Acceptance Criteria

1. THE `uploads` and Browse_Endpoints rate limiters SHALL key limits on the client IP address for unauthenticated Guests.
2. THE rate limiters SHALL NOT rely on browser fingerprinting or collection of personal data beyond the client IP.

### Requirement 9: Rate-Limit Response Behavior (New work — frontend copy; Verify — status)

**User Story:** As a Guest, I want a clear message when I hit a rate limit, so that I understand to wait and retry without seeing internal details.

#### Acceptance Criteria

1. WHEN any Public_Endpoint rate limit is exceeded, THE application SHALL respond with HTTP 429.
2. WHEN the Upload_Endpoint returns HTTP 429, THE public upload UI SHALL display the Rate_Limit_Exceeded_Message.
3. THE HTTP 429 response SHALL NOT expose internal implementation details, stack traces, storage paths, or SQL.

### Requirement 10: File Size and Count Protection (Verify existing behavior)

**User Story:** As an Event_Owner, I want per-file size and per-request count limits enforced on the server, so that oversized or bulk submissions are rejected.

#### Acceptance Criteria

1. THE StorePhotosRequest SHALL reject any upload where a `photos.*` item exceeds Max_Photo_Size.
2. THE StorePhotosRequest SHALL reject any upload where the `photos` array exceeds Max_Photos_Per_Request items.
3. THE size and count limits SHALL be sourced from `uploads.max_file_kb` and `uploads.max_files` config values.

### Requirement 11: Event-Level Photo Cap (New work)

**User Story:** As an Event_Owner, I want a cap on total photos per event, so that a single event cannot be filled beyond a reasonable safeguard.

#### Acceptance Criteria

1. THE application SHALL define Max_Photos_Per_Event as a config value (`uploads.max_per_event`, default 500, env `UPLOAD_MAX_PER_EVENT`).
2. WHEN an upload request is received, THE PublicPhotoUploadController SHALL count the event's Non_Deleted_Photo records before accepting new photos.
3. IF accepting the requested photos would cause the event's Non_Deleted_Photo count to exceed Max_Photos_Per_Event, THEN THE PublicPhotoUploadController SHALL reject the request with HTTP 403 and a friendly message.
4. THE PublicPhotoUploadController SHALL enforce Max_Photos_Per_Event on the server.
5. THE Max_Photos_Per_Event cap SHALL function as an abuse safeguard and SHALL NOT be tied to billing or plans.

### Requirement 12: Queue Abuse Protection and Job Integrity (Verify existing behavior)

**User Story:** As an Event_Owner, I want photo processing jobs to be safe against duplication and invalid state, so that the queue cannot be abused or corrupted.

#### Acceptance Criteria

1. WHEN a photo is accepted, THE PublicPhotoUploadController SHALL dispatch exactly one ProcessPhoto job per accepted photo carrying the integer photo id.
2. WHEN a ProcessPhoto job runs, THE ProcessPhoto job SHALL look up the photo and SHALL complete without action if the photo no longer exists.
3. WHILE a photo is already Ready_Photo with both variant paths present, THE ProcessPhoto job SHALL skip reprocessing.
4. IF the original file is missing when a ProcessPhoto job runs, THEN THE ProcessPhoto job SHALL mark the photo `failed`.
5. THE ProcessPhoto job SHALL retry up to 3 attempts with backoff intervals of 10, 30, and 60 seconds.
6. IF a ProcessPhoto job exhausts its retries, THEN THE ProcessPhoto job SHALL clean up partial variants, mark the photo `failed`, and log the error.

### Requirement 13: Download Protection (Verify existing behavior)

**User Story:** As an Event_Owner, I want downloads restricted to ready photos of the active event, so that guests cannot download other events' or unprocessed files or discover storage paths.

#### Acceptance Criteria

1. WHEN the Download_Endpoint is requested, THE PublicPhotoDownloadController SHALL require the event to be an Active_Event.
2. WHEN the Download_Endpoint is requested, THE PublicPhotoDownloadController SHALL serve only a Ready_Photo that belongs to the resolved event.
3. IF the requested photo does not belong to the resolved event, is not a Ready_Photo, or does not exist, THEN THE PublicPhotoDownloadController SHALL respond with HTTP 404.
4. IF the resolved photo's file does not exist in storage, THEN THE PublicPhotoDownloadController SHALL respond with HTTP 404.
5. THE PublicPhotoDownloadController SHALL NOT expose raw storage paths and SHALL NOT accept an arbitrary file path from the client.

### Requirement 14: Gallery Protection (Verify existing behavior)

**User Story:** As an Event_Owner, I want the gallery to show only ready photos of the active event, so that no draft data or storage internals leak.

#### Acceptance Criteria

1. WHEN the gallery is served, THE PublicGalleryController SHALL require the event to be an Active_Event.
2. WHEN the gallery is served, THE PublicGalleryController SHALL include only Ready_Photo records scoped to the resolved event.
3. THE PublicGalleryController SHALL expose per photo only `uuid`, `thumbnailUrl`, `optimizedUrl`, `filename`, `width`, and `height`.
4. THE PublicGalleryController SHALL derive image URLs through `Storage::url` and SHALL NOT expose raw storage paths or the database id.

### Requirement 15: Data Exposure Review (Verify existing behavior)

**User Story:** As an Event_Owner, I want public responses to carry only necessary fields, so that no internal identifiers or metadata are disclosed.

#### Acceptance Criteria

1. THE PublicEventController SHALL expose only `slug`, `name`, `description`, `event_date`, `location`, `upload_enabled`, and `photoCount`.
2. THE PublicEventController SHALL NOT expose the event `id`, `uuid`, `user_id`, storage paths, or timestamps.
3. THE Public_Endpoints SHALL NOT expose database primary keys, foreign keys, storage paths, or internal timestamps beyond fields required for display.

### Requirement 16: Mass Assignment Protection (Verify existing behavior)

**User Story:** As an Event_Owner, I want server-controlled photo attributes, so that a guest cannot set the owning event, status, or storage paths.

#### Acceptance Criteria

1. WHEN a photo is created on upload, THE PublicPhotoUploadController SHALL build the `Photo::create` array from server-side values only.
2. THE PublicPhotoUploadController SHALL ignore any client-supplied `event_id`, `status`, or `original_path` values.
3. WHEN a photo is created on upload, THE PublicPhotoUploadController SHALL set `status` to the pending state using server logic.

### Requirement 17: CSRF Appropriateness (Verify existing behavior)

**User Story:** As an Event_Owner, I want the upload form protected by CSRF, so that cross-site requests cannot post uploads on a guest's behalf.

#### Acceptance Criteria

1. THE Upload_Endpoint SHALL remain within the default `web` middleware group and SHALL NOT be excluded from CSRF verification.
2. WHEN the public upload UI submits an upload, THE client SHALL send the `X-XSRF-TOKEN` header.
3. THE requirements SHALL document that CSRF protection remains enabled for the Upload_Endpoint.

### Requirement 18: XSS Review (Verify existing behavior)

**User Story:** As an Event_Owner, I want public event content rendered as text, so that user-provided values cannot inject scripts.

#### Acceptance Criteria

1. THE public React components for the event page and gallery SHALL render event-provided fields as text using React's default escaping.
2. THE public React components SHALL NOT render user-provided or event-provided fields via `dangerouslySetInnerHTML`.
3. THE requirements SHALL note that the only `dangerouslySetInnerHTML` usage is the two-factor setup modal rendering a server-generated QR SVG, which is out of scope and reviewed as safe.

### Requirement 19: Security Headers (New work)

**User Story:** As an Event_Owner, I want security response headers on web responses, so that content sniffing and clickjacking are mitigated without breaking the app.

#### Acceptance Criteria

1. THE application SHALL provide a Security_Headers_Middleware applied to web responses.
2. THE Security_Headers_Middleware SHALL add the header `X-Content-Type-Options: nosniff`.
3. THE Security_Headers_Middleware SHALL add framing protection using `X-Frame-Options: SAMEORIGIN` or an equivalent `frame-ancestors` directive.
4. WHILE the Security_Headers_Middleware is active, THE application SHALL continue to serve Inertia pages, Vite development assets, and local images and asset files without failure.

### Requirement 20: Error Handling (New work — document; Verify — behavior)

**User Story:** As a Guest, I want generic error responses, so that failures never reveal internal details.

#### Acceptance Criteria

1. WHEN a Public_Endpoint returns an error, THE application SHALL respond with a generic HTTP 404, 403, or 429 as appropriate.
2. THE Public_Endpoint error responses SHALL NOT expose stack traces, storage paths, or SQL.
3. THE requirements SHALL document that `APP_DEBUG` must be `false` in production (Phase 14) and may remain `true` locally.

### Requirement 21: Security Logging (New work)

**User Story:** As an Event_Owner, I want lightweight logging of suspicious events, so that abuse can be reviewed without recording sensitive data.

#### Acceptance Criteria

1. WHEN an upload fails validation, THE application SHALL log the failure at the warning or notice level.
2. WHEN an upload is rejected as suspicious (for example, exceeding Max_Photos_Per_Event), THE application SHALL log the rejection at the warning or notice level.
3. WHEN a ProcessPhoto job fails, THE ProcessPhoto job SHALL log the error via the existing `Log::error` behavior.
4. THE security logging SHALL NOT record file contents or personally identifiable information.

### Requirement 22: Phase 9 Security Test Matrix (New work)

**User Story:** As an Event_Owner, I want an automated security test matrix, so that the hardened behavior is locked in and Phases 1–8 do not regress.

#### Acceptance Criteria

1. THE test suite SHALL verify event access control: active events resolve; draft, archived, soft-deleted, and nonexistent slugs return HTTP 404.
2. THE test suite SHALL verify event isolation: a cross-event photo `uuid` on the Download_Endpoint returns HTTP 404.
3. THE test suite SHALL verify upload validation: unsupported types, oversized files, and over-count requests are rejected.
4. THE test suite SHALL verify upload rate limiting including a HTTP 429 on exceeding Upload_Rate_Limit and recovery after the rate-limit window resets.
5. THE test suite SHALL verify browsing rate limiting returns HTTP 429 when Browse_Rate_Limit is exceeded.
6. THE test suite SHALL verify download states: non-ready, cross-event, missing-file, and nonexistent photos return HTTP 404.
7. THE test suite SHALL verify mass assignment: client-supplied `event_id`, `status`, and `original_path` are ignored.
8. THE test suite SHALL verify path traversal: a crafted filename does not escape the event's Upload_Storage_Area.
9. THE test suite SHALL verify that a script file (for example, a `.php` payload) is rejected by upload validation.
10. THE test suite SHALL verify no path exposure: Public_Endpoint responses contain no raw storage paths or database ids beyond permitted display fields.
11. THE test suite SHALL verify the event photo cap: an upload exceeding Max_Photos_Per_Event returns HTTP 403.
12. THE test suite SHALL verify the presence of the `X-Content-Type-Options` and framing headers on web responses.
13. THE test suite SHALL include Phase 1–8 regression coverage so hardening changes do not break existing behavior.

## Out of Scope

The following items from the Phase 9 brief context are explicitly out of scope for this spec:

- Redis, Horizon, or any non-database queue/cache backend.
- Cloudflare, AWS/WAF, or any external edge, firewall, or CDN service.
- CAPTCHA or bot-detection services.
- External authentication or security services and browser fingerprinting.
- Cloud storage; all storage remains local on the `public` disk.
- Payments, subscriptions, or billing/plan-based limits (the event cap is an abuse safeguard only).
- AI or machine-learning features.
- Production deployment hardening (separate content domain, `APP_DEBUG=false` enforcement, non-executable content serving), which is deferred to Phase 14 and only documented here.
- Refactoring of already-correct Phase 1–8 features; Phase 9 verifies and locks them with tests only.
- The two-factor setup modal `dangerouslySetInnerHTML` QR rendering, which is reviewed-safe and not public/guest content.

## Verify-vs-New Summary

| # | Capability | Classification |
|---|-----------|----------------|
| 1 | Public endpoint access control | Verify existing behavior |
| 2 | Event isolation / IDOR prevention | Verify existing behavior |
| 3 | Upload validation | Verify existing behavior |
| 4 | Malicious file protection | New (.htaccess) + Verify (validation) |
| 5 | Filename security / path traversal | Verify existing behavior |
| 6 | Upload rate limiting | Verify (limiter) + New (frontend 429 message) |
| 7 | Public browsing rate limiting | New work |
| 8 | Rate-limit identification | New work (encode policy) |
| 9 | Rate-limit response behavior | New (frontend copy) + Verify (status) |
| 10 | File size + count protection | Verify existing behavior |
| 11 | Event-level photo cap (500) | New work |
| 12 | Queue abuse protection / job integrity | Verify existing behavior |
| 13 | Download protection | Verify existing behavior |
| 14 | Gallery protection | Verify existing behavior |
| 15 | Data exposure review | Verify existing behavior |
| 16 | Mass assignment protection | Verify existing behavior |
| 17 | CSRF appropriateness | Verify + document |
| 18 | XSS review | Verify existing behavior |
| 19 | Security headers | New work |
| 20 | Error handling | New (document) + Verify (behavior) |
| 21 | Security logging | New work |
| 22 | Phase 9 security test matrix | New work |
