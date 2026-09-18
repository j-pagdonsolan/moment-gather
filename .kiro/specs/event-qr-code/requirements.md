# Requirements Document

## Introduction

This feature (Phase 4 of MomentGather) gives every event a QR code that points to the event's existing public page at `/e/{slug}`. Organizers can view the QR code on their own event's organizer page, download it as a high-resolution PNG for printing, copy the public link, and open the public page directly. Guests scan the printed QR code to reach the event's public page.

Phases 1-3 (authentication, dashboard, Event CRUD, ownership/authorization via `EventPolicy`, and the public event page at `/e/{slug}`) are already implemented. This feature does not recreate or alter unrelated functionality. It does not implement photo uploads, a photo gallery, image processing, queues, external storage, video, AI, payments, or subscriptions.

### Technical Approach Decision (Client-Side Generation)

The QR code is generated **client-side in React** using the `qrcode.react` npm package. This decision is deliberate and drives these requirements:

- **No server-side QR library**: The environment has neither the `imagick` nor `gd` PHP extension enabled, so server-side PNG generation is not viable. Client-side rendering satisfies all functional needs — the PNG is produced from the browser canvas.
- **No new database column**: The QR code is derived on demand from the event's public URL. Nothing is stored.
- **No new backend route and no new controller**: The existing organizer route `events.show` (`GET /events/{event}`, guarded by `auth` + `verified` middleware and `EventPolicy@view`) is reused. It will additionally pass the event's public URL to the Inertia page.
- **No hardcoded domains**: The public URL is generated server-side with `route('public.events.show', ['slug' => $event->slug])`, which derives the domain from application configuration.
- **Authorization reuse**: Access control relies entirely on the existing `EventPolicy`. No duplicate authorization logic is introduced.

### Scope Summary

- One new npm dependency: `qrcode.react` (React 19 compatible, actively maintained).
- One new reusable React component: `resources/js/components/QrCode.tsx`.
- One new QR Code section on the existing `resources/js/pages/Events/Show.tsx` page (replacing the "QR Code" Coming Soon placeholder card).
- One added Inertia prop (the event's public URL) from `EventController@show`.
- No new backend routes, no new controller, no migration, no database column.

## Glossary

- **Organizer**: An authenticated, verified user who owns one or more events. Ownership is determined by `Event.user_id` matching the authenticated user's id.
- **Owner**: The Organizer whose `user_id` equals a given event's `user_id`.
- **Event_Controller**: The existing `App\Http\Controllers\EventController`; specifically its `show` action bound to the `events.show` route.
- **Organizer_Event_Page**: The Inertia page `resources/js/pages/Events/Show.tsx`, rendered by `Event_Controller` at `GET /events/{event}` (event bound by uuid). Guarded by `auth` + `verified` middleware and `EventPolicy@view`.
- **Public_Event_Page**: The attendee-facing Inertia page `Public/Event`, rendered by `PublicEventController@show` at the route `public.events.show` (path `/e/{slug}`), outside the auth group.
- **Public_URL**: The absolute URL of an event's Public_Event_Page, produced server-side by `route('public.events.show', ['slug' => $event->slug])`.
- **QR_Code_Component**: The reusable React component `resources/js/components/QrCode.tsx` that renders a QR code from a supplied URL and provides download, copy, and open actions.
- **QR_Code_Section**: The "Event QR Code" section rendered on the Organizer_Event_Page that hosts the QR_Code_Component.
- **QR_PNG**: The PNG image of the QR code produced client-side from the rendered canvas.
- **Event_Policy**: The existing `App\Policies\EventPolicy`, whose `view` method returns `true` only when the requesting user's id equals the event's `user_id`.
- **Event_Status**: The `status` attribute of an event; one of `active`, `archived`, or `draft`. The Public_Event_Page resolves only for `active` events that are not soft-deleted.
- **Event_Slug**: The `slug` attribute of an event; a URL-safe, sanitized identifier generated at event creation.
- **Clipboard_API**: The browser `navigator.clipboard` interface used to copy text to the system clipboard.

## Requirements

### Requirement 1: Provide the public URL to the organizer event page

**User Story:** As an Organizer, I want my event's public URL delivered to the organizer event page, so that a QR code pointing to the correct public page can be produced without hardcoded domains.

#### Acceptance Criteria

1. WHEN an Owner requests the Organizer_Event_Page for an owned event, THE Event_Controller SHALL include the event's Public_URL in the Inertia page props.
2. THE Event_Controller SHALL generate the Public_URL using `route('public.events.show', ['slug' => $event->slug])`.
3. THE Public_URL SHALL contain the event's Event_Slug.
4. THE Public_URL SHALL resolve to the path `/e/{slug}` for the event's Event_Slug.
5. THE Event_Controller SHALL continue to include the existing event data in the Inertia page props.

### Requirement 2: Enforce owner-only access to the organizer QR functionality

**User Story:** As an Organizer, I want only the event owner to reach the QR functionality on the organizer page, so that no other organizer can view or download my event's QR code through organizer routes.

#### Acceptance Criteria

1. WHEN an Owner requests the Organizer_Event_Page for an owned event, THE Event_Controller SHALL render the page with the Public_URL prop.
2. IF a requesting user is not the Owner of the target event, THEN THE Event_Controller SHALL deny access with an HTTP 403 response.
3. THE Event_Controller SHALL determine access using Event_Policy `view` without introducing additional authorization logic.
4. IF an unauthenticated visitor requests the Organizer_Event_Page, THEN THE application SHALL redirect the visitor to authentication via the existing `auth` middleware.

### Requirement 3: Display the QR code section on the organizer event page

**User Story:** As an Organizer, I want a clear QR code section on my event page, so that I understand and can use the QR code immediately.

#### Acceptance Criteria

1. THE QR_Code_Section SHALL display the heading text "Event QR Code".
2. THE QR_Code_Section SHALL display the supporting text "Guests can scan this QR code to open your event page."
3. THE QR_Code_Section SHALL render the QR_Code_Component using the Public_URL supplied to the Organizer_Event_Page.
4. THE QR_Code_Section SHALL display the Public_URL as readable text.
5. THE QR_Code_Section SHALL provide a "Download QR Code" control, a "Copy Link" control, and an "Open Event" control.
6. THE QR_Code_Section SHALL replace the existing "QR Code" placeholder card on the Organizer_Event_Page.

### Requirement 4: Encode the public URL in the QR code

**User Story:** As a guest, I want to scan the QR code and land on the event's public page, so that I can view the event.

#### Acceptance Criteria

1. THE QR_Code_Component SHALL encode the Public_URL supplied through its props.
2. WHEN a guest scans the rendered QR code, THE encoded value SHALL direct the guest to the event's Public_Event_Page at `/e/{slug}`.
3. THE QR_Code_Component SHALL derive its encoded value only from the supplied Public_URL prop.

### Requirement 5: Download the QR code as a printable PNG

**User Story:** As an Organizer, I want to download the QR code as a high-resolution PNG, so that I can print it for guests to scan.

#### Acceptance Criteria

1. WHEN an Owner activates the "Download QR Code" control, THE QR_Code_Component SHALL produce a QR_PNG from the rendered canvas.
2. THE QR_PNG SHALL render the QR code on a white background.
3. THE QR_PNG SHALL include a quiet-zone margin of at least 4 modules around the QR code.
4. THE QR_PNG SHALL have a pixel dimension of at least 1024 pixels on each side.
5. WHEN the QR_PNG is produced, THE QR_Code_Component SHALL name the downloaded file `momentgather-{slug}-qr.png`, where `{slug}` is the event's Event_Slug.
6. THE QR_Code_Component SHALL derive the download filename from the Event_Slug rather than from raw user-entered text.

### Requirement 6: Copy the public URL to the clipboard

**User Story:** As an Organizer, I want to copy my event's public link with one action, so that I can share it without printing the QR code.

#### Acceptance Criteria

1. WHEN an Owner activates the "Copy Link" control, THE QR_Code_Component SHALL write the Public_URL to the clipboard using the Clipboard_API.
2. WHEN the clipboard write succeeds, THE QR_Code_Component SHALL display a confirmation message "Link copied!".
3. IF the Clipboard_API is unavailable or the clipboard write fails, THEN THE QR_Code_Component SHALL report the failure to the Organizer without terminating the page.

### Requirement 7: Open the public event page from the organizer page

**User Story:** As an Organizer, I want to open the public event page directly, so that I can preview what guests see when they scan the QR code.

#### Acceptance Criteria

1. THE "Open Event" control SHALL link to the Public_URL supplied to the Organizer_Event_Page.
2. WHEN an Owner activates the "Open Event" control, THE application SHALL navigate to the event's Public_Event_Page.

### Requirement 8: Generate the QR code dynamically without persistence

**User Story:** As a system maintainer, I want the QR code produced on demand, so that no duplicate image files or database columns are introduced.

#### Acceptance Criteria

1. THE QR_Code_Component SHALL generate the QR code at render time from the Public_URL prop.
2. THE application SHALL store neither the QR_PNG nor any QR-related value in the database.
3. THE application SHALL add no database column for the QR code.

### Requirement 9: Preserve existing public event behavior

**User Story:** As a guest, I want the public event page to behave exactly as before, so that scanning a QR code follows the established visibility rules.

#### Acceptance Criteria

1. THE application SHALL serve the Public_Event_Page through the existing `public.events.show` route without adding another public route.
2. WHEN a guest requests the Public_URL for an event whose Event_Status is `active` and that is not soft-deleted, THE PublicEventController SHALL respond with HTTP 200 and render the `Public/Event` page.
3. IF a guest requests the Public_URL for an event whose Event_Status is `archived` or `draft`, THEN THE PublicEventController SHALL respond with HTTP 404.
4. IF a guest requests the Public_URL for a soft-deleted event, THEN THE PublicEventController SHALL respond with HTTP 404.

### Requirement 10: Provide the QR functionality regardless of the owner's event status

**User Story:** As an Organizer, I want to see the QR section for any of my events, so that I can prepare a QR code before an event becomes active.

#### Acceptance Criteria

1. WHERE an Owner requests the Organizer_Event_Page for an owned event, THE Event_Controller SHALL render the QR_Code_Section regardless of the event's Event_Status.
2. THE Event_Controller SHALL include the Public_URL prop regardless of the event's Event_Status.

### Requirement 11: Render the QR section responsively

**User Story:** As an Organizer, I want the QR section to work on any device, so that I can access it from a phone, tablet, or desktop.

#### Acceptance Criteria

1. THE QR_Code_Section SHALL render the QR code, the Public_URL text, and all controls on viewport widths from mobile through desktop.
2. THE QR_Code_Section SHALL render the QR code at a size that remains scannable on small viewport widths without occupying the full viewport width.

### Requirement 12: Add the client-side QR dependency

**User Story:** As a developer, I want the QR library declared as a project dependency, so that the client-side QR rendering builds reliably.

#### Acceptance Criteria

1. THE project SHALL declare `qrcode.react` as a dependency in `package.json`.
2. THE application SHALL render QR codes using the `qrcode.react` package rather than a server-side QR library.
