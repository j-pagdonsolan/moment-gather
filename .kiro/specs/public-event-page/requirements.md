# Requirements Document

## Introduction

Phase 3 of MomentGather introduces a **public event page** that attendees can reach at the route `/e/{slug}` (for example, `/e/john-jane-wedding`) without authenticating. This page will later become the destination of a scannable QR code, but this phase covers only the page itself. Photo uploads, galleries, and QR generation are explicitly out of scope.

The page is built on the existing Phase 2 Event CRUD foundation. No schema changes, no new status values, and no cover-image column are introduced. The feature reuses the existing `events` table, the existing `Event` model, and the existing Inertia + React frontend conventions.

**Visibility rule (central to this feature):** Only events with `status = 'active'` that are not soft-deleted are publicly visible. Every other case — an `archived` event, a soft-deleted event, a nonexistent slug, or any hypothetical non-active status — resolves to a standard 404 (not found) response. This single server-side rule satisfies the brief's requirement that draft/archived/deleted events must not be publicly accessible, without adding a `draft` status to the database. The `draft`, `archived`, and `deleted` protection is therefore expressed as: "anything that is not an active, non-deleted event returns 404."

**Cover image:** No cover-image column exists and none is added. The public page renders a clean placeholder in the cover region.

**Privacy:** The public page exposes only attendee-relevant event details (name, optional description, optional date, optional location, upload availability). It never exposes organizer email, organizer user ID, internal database ID, UUID, or any other private data.

## Glossary

- **Public_Event_Page**: The attendee-facing web page rendered at `/e/{slug}` that requires no authentication.
- **Public_Event_Controller**: The dedicated backend controller (`PublicEventController`) that resolves the slug, enforces visibility, and returns the Inertia page. Distinct from the organizer-facing `EventController`.
- **Event**: A record in the existing `events` table, represented by the existing `Event` model.
- **Slug**: The URL-safe unique identifier stored in the `events.slug` column, used to locate an event for the public page (not the database id or uuid).
- **Active_Event**: An `Event` whose `status` column equals the string `'active'` and whose `deleted_at` is null (not soft-deleted).
- **Visibility_Rule**: The server-side condition that an `Event` is publicly viewable only when it is an Active_Event.
- **Upload_Enabled_Flag**: The existing boolean `events.upload_enabled` column that indicates whether photo uploads are open for an event.
- **Upload_Photos_CTA**: The primary call-to-action button labeled "Upload Photos" shown when the Upload_Enabled_Flag is true. It is a non-functional placeholder in this phase.
- **View_Gallery_CTA**: The secondary call-to-action button labeled "View Gallery". It is a non-functional placeholder in this phase.
- **Uploads_Closed_Message**: The text "Photo uploads are currently closed." shown in place of the Upload_Photos_CTA when the Upload_Enabled_Flag is false.
- **Cover_Placeholder**: A static visual placeholder rendered in the cover region because no cover-image data exists.
- **Public_Event_Payload**: The set of event fields passed from the Public_Event_Controller to the React page for rendering.
- **Organizer_Private_Data**: Fields that MUST NOT appear in the Public_Event_Payload or rendered page: organizer email, organizer user ID (`user_id`), internal database id, and uuid.

## Requirements

### Requirement 1: Public Event Route

**User Story:** As an attendee, I want to open an event page from a shared link at `/e/{slug}`, so that I can view the event without creating an account or logging in.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL be served from the route path `/e/{slug}` using the HTTP GET method.
2. THE Public_Event_Page SHALL be accessible without authentication.
3. WHEN a GET request is received at `/e/{slug}`, THE Public_Event_Controller SHALL locate the Event by matching the request slug against the `events.slug` column.
4. IF no Event matches the request slug, THEN THE Public_Event_Controller SHALL return an HTTP 404 response.
5. THE Public_Event_Route SHALL be registered outside the organizer authentication middleware group.

### Requirement 2: Event Visibility Enforcement

**User Story:** As an organizer, I want only active events to be publicly viewable, so that archived or removed events are not exposed to attendees.

#### Acceptance Criteria

1. WHEN a GET request is received at `/e/{slug}` for an Active_Event, THE Public_Event_Controller SHALL return the Public_Event_Page with an HTTP 200 response.
2. IF the matched Event has a `status` other than `'active'`, THEN THE Public_Event_Controller SHALL return an HTTP 404 response.
3. IF the matched Event is soft-deleted, THEN THE Public_Event_Controller SHALL return an HTTP 404 response.
4. THE Public_Event_Controller SHALL evaluate the Visibility_Rule using the Event `status` and `deleted_at` values from the database.
5. THE Public_Event_Controller SHALL NOT use request query parameters to determine whether an Event is publicly viewable.

### Requirement 3: Dedicated Public Event Controller

**User Story:** As a developer, I want public page logic isolated in its own controller, so that organizer CRUD behavior remains unchanged and public concerns stay separated.

#### Acceptance Criteria

1. THE Public_Event_Controller SHALL be a dedicated controller named `PublicEventController`, separate from the organizer `EventController`.
2. THE Public_Event_Controller SHALL resolve the Event by slug scoped to the Visibility_Rule.
3. THE Public_Event_Controller SHALL render the Inertia page located at `Public/Event`.
4. THE Public_Event_Controller SHALL pass a Public_Event_Payload containing only attendee-relevant fields to the rendered page.

### Requirement 4: Public React Page Content

**User Story:** As an attendee, I want to see the event's key details on one clear page, so that I understand what the event is and what I can do next.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL be implemented as a React page at `resources/js/pages/Public/Event.tsx`.
2. THE Public_Event_Page SHALL display the Event name.
3. WHERE the Event has a non-empty description, THE Public_Event_Page SHALL display the Event description.
4. WHERE the Event has a non-null event date, THE Public_Event_Page SHALL display the Event date.
5. WHERE the Event has a non-empty location, THE Public_Event_Page SHALL display the Event location.
6. THE Public_Event_Page SHALL display the Cover_Placeholder in the cover region.
7. THE Public_Event_Page SHALL display the View_Gallery_CTA labeled "View Gallery".
8. THE View_Gallery_CTA SHALL be a non-functional placeholder that performs no gallery navigation in this phase.

### Requirement 5: Mobile-First Presentation

**User Story:** As an attendee opening the link on my phone, I want a fast, clear, mobile-friendly layout, so that I can immediately understand the event and reach the primary action.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL render a primary heading with the text "Share Your Moments".
2. THE Public_Event_Page SHALL present the Upload_Photos_CTA as the primary call-to-action with the label "Upload Photos".
3. THE Public_Event_Page SHALL present the View_Gallery_CTA as the secondary call-to-action.
4. THE Public_Event_Page SHALL apply a responsive layout that renders legibly on mobile viewports and desktop viewports.
5. THE Public_Event_Page SHALL arrange content in a visual hierarchy that presents the primary call-to-action prominently.

### Requirement 6: Upload Status Display

**User Story:** As an attendee, I want to know whether I can upload photos to this event, so that I am not confused when uploads are closed.

#### Acceptance Criteria

1. WHERE the Upload_Enabled_Flag is true, THE Public_Event_Page SHALL display the Upload_Photos_CTA.
2. WHERE the Upload_Enabled_Flag is false, THE Public_Event_Page SHALL display the Uploads_Closed_Message with the text "Photo uploads are currently closed." in place of the Upload_Photos_CTA.
3. THE Public_Event_Page SHALL derive upload availability solely from the Upload_Enabled_Flag value in the Public_Event_Payload.
4. THE Public_Event_Page SHALL NOT modify the Upload_Enabled_Flag value.
5. THE Upload_Photos_CTA SHALL be a non-functional placeholder that performs no upload in this phase.

### Requirement 7: Event Information Formatting and Privacy

**User Story:** As an attendee, I want event details shown in a friendly, readable format without any private organizer data, so that the page is clear and the organizer's information stays protected.

#### Acceptance Criteria

1. WHERE the Event has a non-null event date, THE Public_Event_Page SHALL display the date in a human-readable format such as "September 20, 2026".
2. WHERE the Event has no location value, THE Public_Event_Page SHALL omit the location from the rendered page.
3. WHERE the Event has no description value, THE Public_Event_Page SHALL omit the description from the rendered page.
4. THE Public_Event_Payload SHALL exclude Organizer_Private_Data.
5. THE Public_Event_Page SHALL exclude Organizer_Private_Data from the rendered output.

### Requirement 8: Cover Placeholder

**User Story:** As an attendee, I want a clean visual for the event even when no cover image exists, so that the page looks complete.

#### Acceptance Criteria

1. THE Public_Event_Page SHALL render the Cover_Placeholder as a static visual element.
2. THE Public_Event_Page SHALL render the Cover_Placeholder without requiring any cover-image data.
3. THE public-event-page feature SHALL NOT introduce a cover-image column, image storage, or image processing.

### Requirement 9: Dynamic Page Title for Sharing

**User Story:** As an attendee, I want the browser tab and shared link to show the event name, so that I can identify the event at a glance.

#### Acceptance Criteria

1. WHEN the Public_Event_Page renders for an Active_Event, THE Public_Event_Page SHALL set the page title to the format "{Event name} | MomentGather".
2. THE Public_Event_Page SHALL derive the page title from the Event name in the Public_Event_Payload.

### Requirement 10: Server-Side Security

**User Story:** As an organizer, I want visibility and privacy enforced on the server, so that attendees cannot bypass rules or view private data through client manipulation.

#### Acceptance Criteria

1. THE Public_Event_Controller SHALL enforce the Visibility_Rule on the server before rendering the Public_Event_Page.
2. THE Public_Event_Controller SHALL exclude Organizer_Private_Data from the Public_Event_Payload.
3. IF a request supplies query parameters, THEN THE Public_Event_Controller SHALL ignore those parameters when determining Event visibility.

### Requirement 11: Feature Tests

**User Story:** As a developer, I want automated feature tests for the public page, so that visibility, privacy, and content behavior remain correct over time.

#### Acceptance Criteria

1. THE public-event-page test suite SHALL verify that an Active_Event is accessible by its slug and returns an HTTP 200 response.
2. THE public-event-page test suite SHALL verify that a request for a nonexistent slug returns an HTTP 404 response.
3. THE public-event-page test suite SHALL verify that an Event with a non-active status other than active (representing the draft case) returns an HTTP 404 response.
4. THE public-event-page test suite SHALL verify that an archived Event returns an HTTP 404 response.
5. THE public-event-page test suite SHALL verify that a soft-deleted Event returns an HTTP 404 response.
6. THE public-event-page test suite SHALL verify that the Public_Event_Page is reachable without authentication.
7. THE public-event-page test suite SHALL verify that the rendered Public_Event_Payload contains the Event name, and the description, date, and location when present.
8. WHERE the Upload_Enabled_Flag is true, THE public-event-page test suite SHALL verify that the Public_Event_Page presents the Upload_Photos_CTA.
9. WHERE the Upload_Enabled_Flag is false, THE public-event-page test suite SHALL verify that the Public_Event_Page presents the Uploads_Closed_Message.
10. THE public-event-page test suite SHALL verify that the Public_Event_Payload excludes Organizer_Private_Data.

### Requirement 12: Scope Exclusions

**User Story:** As a stakeholder, I want this phase tightly scoped, so that only the public page is built and later phases remain unaffected.

#### Acceptance Criteria

1. THE public-event-page feature SHALL NOT implement photo uploads, a photos table, or a gallery.
2. THE public-event-page feature SHALL NOT implement QR code generation.
3. THE public-event-page feature SHALL NOT introduce image processing, cloud object storage, queues, or a Redis dependency.
4. THE public-event-page feature SHALL NOT modify the events migration, the Event model fillable or casts, or organizer validation rules.
5. THE public-event-page feature SHALL NOT add a `draft` status value to the database or validation.
