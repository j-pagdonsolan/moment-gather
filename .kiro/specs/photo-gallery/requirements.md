# Requirements Document

## Introduction

This document specifies the requirements for **Phase 6 of MomentGather: the public Photo Gallery**. The gallery lets event guests — with no account and no authentication — view all successfully-uploaded photos of an *active* event, open any photo in a full-screen lightbox viewer, page through additional photos with a "Load More" control, and download an individual photo.

Phases 1–5 (authentication, organizer dashboard, Event CRUD/status, public event page `/e/{slug}`, QR code, guest photo upload) are already implemented. This feature MUST NOT recreate the project or alter unrelated functionality, and all existing Phase 1–5 tests MUST continue to pass.

### Key technical decisions (decided; requirements are built around these)

1. **Display via direct public-disk Storage URLs.** Each photo is rendered with an `<img>` whose `src` is produced by `Storage::disk('public')->url($photo->original_path)`, yielding a `/storage/...` public URL. This is fast and exposes no server filesystem path. The `public` disk is already web-servable (`php artisan storage:link` done in Phase 5). Phase 5 upload storage is not modified.
2. **Event-scoped, uuid-bound download route.** Download uses a dedicated route `GET /e/{slug}/photos/{photo}/download`. The route (a) resolves the active event by slug, (b) resolves the photo by its `uuid` and verifies the photo belongs to that event server-side, (c) streams the file from the `public` disk as a forced download (`Content-Disposition: attachment`) using the stored `original_filename`, and (d) handles a missing file with a safe 404 that leaks no path or server detail. Photos are always referenced by `uuid`, never by database `id`.

### Scope guardrails

- **Local development only.** No S3 / DigitalOcean Spaces / CDN, no Redis / queues / Horizon, no image processing / resizing / thumbnails / WebP / EXIF, no AI, no duplicate detection, no payments / subscriptions, no analytics, no advanced moderation, no video. Image processing is Phase 7.
- **Server-side event scoping is mandatory.** The gallery for an event returns *only* that event's photos and *only* photos with status `ready`. Filtering happens in the backend query, never in React. Guests cannot reach `draft`, `archived`, soft-deleted, or nonexistent events (all → 404), and cannot fetch another event's photo through this event's download route.
- **Minimal footprint.** Two new public routes (gallery `GET`, download `GET`); one controller (or two small controllers/actions); no database migration; no model changes except possibly a `getRouteKeyName` override or a query scope on `Photo`; a new `Gallery.tsx` page plus small grid/card/viewer components; and a small tweak to `Public/Event.tsx` and `PublicEventController@show` (wire the "View Gallery" link and an optional ready-photo count).

## Glossary

- **Gallery_System**: The backend components (route, controller/action, query) that resolve an active event and return its `ready` photos, paginated, for public gallery display.
- **Download_System**: The backend components (route, controller/action) that resolve an active event and one of its `ready`-eligible photos by `uuid`, verify ownership, and stream the file as a forced download.
- **Gallery_Page**: The public React page (`resources/js/pages/Public/Gallery.tsx`) that renders the gallery, grid, lightbox viewer, and Load More control.
- **Public_Event_Page**: The existing public React page (`resources/js/pages/Public/Event.tsx`) shown at `/e/{slug}`.
- **Photo_Viewer**: The full-screen lightbox React component (`PhotoViewer.tsx`) that displays a single enlarged photo with navigation and download.
- **Photo_Grid**: The responsive grid React component(s) (`PhotoGrid.tsx` / `PhotoCard.tsx`) that display photo thumbnails.
- **Active_Event**: An `Event` whose `status = 'active'` and which is not soft-deleted. The only events publicly resolvable.
- **Ready_Photo**: A `Photo` whose `status = 'ready'` (a successful upload) and which is not soft-deleted. The only photos eligible for gallery display or download.
- **Display_URL**: The public web URL for a photo's stored file, produced by `Storage::disk('public')->url($photo->original_path)` (a `/storage/...` path).
- **Storage_Path**: The server-side relative path stored in `Photo.original_path` (e.g. `events/{event_uuid}/originals/{photo_uuid}.{ext}`) on the `public` disk. Never exposed to the client as a raw filesystem path.
- **Page_Size**: The number of photos returned per gallery page. Fixed at 24.
- **Load_More**: The client control and mechanism that requests the next page of photos and appends them to the currently displayed set without duplicating already-shown photos.
- **Ready_Photo_Count**: The count of `Ready_Photo` records for an `Active_Event`.
- **Guest**: An unauthenticated visitor of a public event page or gallery.

## Requirements

### Requirement 1: Public gallery route and event resolution

**User Story:** As a guest, I want to open an event's gallery by its public URL without signing in, so that I can view the event's shared photos.

#### Acceptance Criteria

1. THE Gallery_System SHALL expose a route `GET /e/{slug}/gallery` named `public.events.gallery` registered outside the authentication group.
2. WHEN a request for `GET /e/{slug}/gallery` names a slug that matches an Active_Event, THE Gallery_System SHALL render the Gallery_Page for that event.
3. IF a request for `GET /e/{slug}/gallery` names a slug that matches no Event, THEN THE Gallery_System SHALL respond with HTTP status 404.
4. IF a request for `GET /e/{slug}/gallery` names a slug that matches an Event whose status is not `active`, THEN THE Gallery_System SHALL respond with HTTP status 404.
5. IF a request for `GET /e/{slug}/gallery` names a slug that matches a soft-deleted Event, THEN THE Gallery_System SHALL respond with HTTP status 404.
6. THE Gallery_System SHALL resolve the event using the same visibility rule as the public event page (`slug` match, `status = 'active'`, not soft-deleted).

### Requirement 2: Server-side ready-and-event scoping of gallery photos

**User Story:** As an event organizer, I want only my event's successful photos shown in my gallery, so that unfinished, failed, deleted, or other events' photos are never exposed.

#### Acceptance Criteria

1. WHEN the Gallery_System returns photos for a resolved Active_Event, THE Gallery_System SHALL include only photos whose `event_id` equals that event's id.
2. WHEN the Gallery_System returns photos for a resolved Active_Event, THE Gallery_System SHALL include only Ready_Photo records (status `ready`, not soft-deleted).
3. WHEN the Gallery_System selects photos for display, THE Gallery_System SHALL exclude photos with status `pending`, `processing`, `failed`, or `deleted`.
4. THE Gallery_System SHALL retrieve gallery photos through the `Event` `photos()` relationship scoped to Ready_Photo records.
5. THE Gallery_System SHALL apply the ready-and-event filtering in the backend query and SHALL NOT rely on client-side filtering to enforce it.

### Requirement 3: Gallery payload contents and field minimization

**User Story:** As a security-conscious maintainer, I want the gallery payload to contain only the fields the client needs, so that internal identifiers and server paths are never exposed.

#### Acceptance Criteria

1. WHEN the Gallery_System serializes a photo for the Gallery_Page, THE Gallery_System SHALL include the photo's `uuid`, Display_URL, `original_filename`, `width`, `height`, and `mime_type`.
2. WHEN the Gallery_System serializes a photo for the Gallery_Page, THE Gallery_System SHALL exclude the photo's database `id` and `event_id`.
3. WHEN the Gallery_System serializes a photo for the Gallery_Page, THE Gallery_System SHALL exclude the raw Storage_Path filesystem value, providing the Display_URL instead.
4. THE Gallery_System SHALL construct each Display_URL using `Storage::disk('public')->url($photo->original_path)`.
5. WHEN the Gallery_System queries photos, THE Gallery_System SHALL select only the columns required to build the payload.

### Requirement 4: Gallery pagination and Load More

**User Story:** As a guest browsing a large event, I want to load photos in pages, so that the gallery stays fast and does not fetch every photo at once.

#### Acceptance Criteria

1. THE Gallery_System SHALL paginate gallery photos with a Page_Size of 24.
2. WHEN a request for the gallery names page number `n`, THE Gallery_System SHALL return the Ready_Photo records for page `n` in a stable order.
3. WHEN the Gallery_System returns a page of photos, THE Gallery_System SHALL provide the information needed to determine whether a subsequent page exists.
4. WHEN a guest activates the Load_More control, THE Gallery_Page SHALL request the next page and append its photos to the currently displayed set.
5. WHEN the Gallery_Page appends a newly loaded page, THE Gallery_Page SHALL exclude any photo whose `uuid` already appears in the displayed set.
6. WHILE the displayed set already contains the last available page, THE Gallery_Page SHALL hide or disable the Load_More control.
7. THE Gallery_System SHALL NOT return all of an event's photos in a single response.

### Requirement 5: Responsive gallery layout and lazy loading

**User Story:** As a guest on any device, I want a clean responsive grid of photos that loads smoothly, so that I can browse comfortably on mobile or desktop.

#### Acceptance Criteria

1. THE Gallery_Page SHALL display the event name and a "Shared Photo Gallery" heading.
2. THE Gallery_Page SHALL render photos in a responsive Photo_Grid using approximately 2 columns on mobile viewports and approximately 4 columns on desktop viewports.
3. WHEN the Gallery_Page renders a photo, THE Gallery_Page SHALL reserve layout space for the photo using the photo's stored `width` and `height` to reduce layout shift.
4. WHEN the Gallery_Page renders a photo image, THE Gallery_Page SHALL set the image `loading` attribute to `lazy`.
5. THE Gallery_Page SHALL use the standalone public layout (Public/) consistent with the existing public event page.

### Requirement 6: Full-screen photo viewer (lightbox)

**User Story:** As a guest, I want to open a photo full-screen and move between photos, so that I can view moments in detail.

#### Acceptance Criteria

1. WHEN a guest selects a photo in the Photo_Grid, THE Photo_Viewer SHALL open and display the selected photo enlarged.
2. WHEN a guest activates the close control of the Photo_Viewer, THE Photo_Viewer SHALL close and return to the Gallery_Page.
3. WHEN a guest presses the Escape key while the Photo_Viewer is open, THE Photo_Viewer SHALL close and return to the Gallery_Page.
4. WHEN a guest activates the next control while the Photo_Viewer is open, THE Photo_Viewer SHALL display the next photo in the displayed set.
5. WHEN a guest activates the previous control while the Photo_Viewer is open, THE Photo_Viewer SHALL display the previous photo in the displayed set.
6. WHILE the Photo_Viewer is open on a desktop viewport, WHEN a guest presses the ArrowRight key, THE Photo_Viewer SHALL display the next photo in the displayed set.
7. WHILE the Photo_Viewer is open on a desktop viewport, WHEN a guest presses the ArrowLeft key, THE Photo_Viewer SHALL display the previous photo in the displayed set.
8. WHEN the Photo_Viewer closes, THE Gallery_Page SHALL preserve the scroll position that was active when the Photo_Viewer opened.
9. THE Photo_Viewer SHALL function on both mobile and desktop viewports.

### Requirement 7: Event-scoped photo download

**User Story:** As a guest, I want to download an individual photo, so that I can keep a copy of a moment from the event.

#### Acceptance Criteria

1. THE Download_System SHALL expose a route `GET /e/{slug}/photos/{photo}/download` registered outside the authentication group, resolving the photo parameter by `uuid`.
2. WHEN a request for the download route names a slug matching an Active_Event and a photo `uuid` whose photo belongs to that event, THE Download_System SHALL stream that photo's file from the `public` disk.
3. WHEN the Download_System streams a photo file, THE Download_System SHALL set a `Content-Disposition: attachment` header using the photo's stored `original_filename`.
4. IF a download request names a photo `uuid` whose photo's `event_id` does not equal the resolved event's id, THEN THE Download_System SHALL respond with HTTP status 404.
5. IF a download request names a slug that does not resolve to an Active_Event, THEN THE Download_System SHALL respond with HTTP status 404.
6. IF a download request names a photo `uuid` that matches no photo, THEN THE Download_System SHALL respond with HTTP status 404.
7. THE Download_System SHALL verify the photo-to-event relationship on the server and SHALL NOT rely on the client to enforce it.
8. WHEN the Download_System serves a download, THE Download_System SHALL reference the photo by `uuid` and SHALL NOT accept a database `id` as the photo identifier.

### Requirement 8: Graceful handling of missing files

**User Story:** As a guest, I want the gallery to keep working even if a photo's file is missing, so that one broken file does not break the whole page or leak server details.

#### Acceptance Criteria

1. IF a photo's file is absent from the `public` disk when the Gallery_Page renders that photo, THEN THE Gallery_Page SHALL show a placeholder or "Photo unavailable" indicator in place of the image without breaking the rest of the grid.
2. IF a download request resolves a valid Ready_Photo but the underlying file is absent from the `public` disk, THEN THE Download_System SHALL respond with HTTP status 404.
3. WHEN the Download_System responds to a missing-file condition, THE Download_System SHALL exclude any filesystem path, storage credential, or server stack detail from the response.

### Requirement 9: Empty gallery state

**User Story:** As a guest arriving at an event with no photos yet, I want a friendly prompt to contribute, so that I know the gallery is empty and how to add a photo.

#### Acceptance Criteria

1. WHILE a resolved Active_Event has zero Ready_Photo records, THE Gallery_Page SHALL display the message "No photos yet".
2. WHILE a resolved Active_Event has zero Ready_Photo records, THE Gallery_Page SHALL display the message "Be the first to share a moment from this event."
3. WHILE a resolved Active_Event has zero Ready_Photo records, THE Gallery_Page SHALL display an "Upload Photos" control that links to the existing event page `/e/{slug}`.
4. WHEN a guest activates the "Upload Photos" control in the empty state, THE Gallery_Page SHALL navigate to `/e/{slug}` and SHALL NOT present a second uploader on the Gallery_Page.

### Requirement 10: Public event page integration

**User Story:** As a guest on the public event page, I want a working link to the gallery, so that I can move from the event page to viewing photos.

#### Acceptance Criteria

1. WHEN a guest activates the "View Gallery" control on the Public_Event_Page, THE Public_Event_Page SHALL navigate to `/e/{slug}/gallery`.
2. WHERE the Public_Event_Page displays a photo count on the gallery link, THE Public_Event_Page SHALL display the Ready_Photo_Count supplied by `PublicEventController@show`.
3. WHERE `PublicEventController@show` supplies a photo count, THE PublicEventController SHALL compute that count as the number of Ready_Photo records for the resolved Active_Event.
4. THE integration SHALL leave the existing Public_Event_Page uploader and payload fields (`slug`, `name`, `description`, `event_date`, `location`, `upload_enabled`) functioning unchanged.

### Requirement 11: Preservation of existing functionality and scope

**User Story:** As a maintainer, I want this feature to add the gallery without disturbing prior phases or introducing out-of-scope infrastructure, so that the application stays stable and local-only.

#### Acceptance Criteria

1. THE feature SHALL leave the Phase 5 upload storage behavior and paths unchanged.
2. THE feature SHALL operate using the existing `public` disk and SHALL NOT introduce S3, DigitalOcean Spaces, CDN, Redis, queues, or Horizon.
3. WHEN a photo is displayed or downloaded, THE feature SHALL serve the stored original file and SHALL NOT perform image resizing, compression, thumbnail generation, format conversion, or EXIF processing.
4. THE feature SHALL preserve the passing state of the existing Phase 1–5 automated tests.
5. THE feature SHALL add no database migration and SHALL limit model changes to at most a `getRouteKeyName` override or a query scope on the `Photo` model.
