# Requirements Document

## Introduction

MomentGather is an event photo-sharing SaaS. This document covers the foundational phase: application branding, organizer event management, a statistics dashboard, and the server-side authorization layer. Future phases will add photo uploads, QR codes, and public event pages for attendees.

The existing Laravel 13 application already provides: authentication via Laravel Fortify (email/password, passkeys, 2FA), a React 19 + Inertia 3 + Tailwind 4 frontend, a sidebar layout with shadcn/ui components, and Wayfinder for type-safe route helpers. This feature builds on that foundation without replacing or reinstalling any of it.

## Glossary

- **Application**: The MomentGather Laravel application as a whole.
- **Organizer**: An authenticated user who creates and manages events.
- **Event**: A record representing a real-world event created by an Organizer.
- **EventPolicy**: The Laravel Gate policy that enforces Organizer-scoped authorization on Event actions.
- **EventController**: The Laravel controller that handles all organizer-facing Event HTTP requests.
- **DashboardController**: The Laravel controller that aggregates and returns event statistics for the dashboard page.
- **EventRequest**: The Laravel Form Request that validates input when creating or updating an Event.
- **EventFactory**: The Laravel model factory used to generate test Event records.
- **SlugGenerator**: The application component responsible for deriving a unique URL-safe slug from an event name.
- **Slug**: A lowercase, hyphenated string derived from the event name, unique among all non-deleted events owned by the same Application.
- **UUID**: A version-4 universally unique identifier assigned to each Event at creation and used in all public-facing URLs.
- **SoftDelete**: Laravel's soft-delete mechanism: records are flagged with `deleted_at` rather than removed from the database.
- **Status**: An event lifecycle value; one of `active` or `archived`.
- **UploadEnabled**: A boolean flag on Event indicating whether photo uploads are permitted (reserved for a future phase).
- **Dashboard**: The `/dashboard` page displaying event statistics and recent events for the authenticated Organizer.
- **EventIndex**: The `/events` page listing all events belonging to the authenticated Organizer.
- **EventShow**: The `/events/{event}` page displaying full details for a single Event.
- **EventCreate**: The `/events/create` page containing the form to create a new Event.
- **EventEdit**: The `/events/{event}/edit` page containing the form to edit an existing Event.
- **AppSidebar**: The existing sidebar navigation component (`resources/js/components/app-sidebar.tsx`).
- **AppLayout**: The existing authenticated page layout (`resources/js/layouts/app-layout.tsx`).

---

## Requirements

### Requirement 1: Application Branding

**User Story:** As an Organizer, I want the application to identify itself as MomentGather, so that the product feels distinct from the default Laravel starter kit.

#### Acceptance Criteria

1. THE Application SHALL display "MomentGather" as the application name in the browser tab title, the sidebar logo text label, and the primary heading on the welcome page.
2. WHEN the `APP_NAME` environment variable is not set, THE Application SHALL use "MomentGather" as the default application name value.
3. WHEN the `APP_NAME` environment variable is set to a non-empty value, THE Application SHALL use that value as the application name displayed in the browser tab title, sidebar logo text label, and welcome page heading.

---

### Requirement 2: Organizer Authentication Guard

**User Story:** As an Organizer, I want all event management pages to require authentication, so that unauthenticated visitors cannot access or manipulate my events.

#### Acceptance Criteria

1. WHEN an unauthenticated visitor sends a GET request to `/dashboard`, `/events`, `/events/create`, `/events/{event}`, or `/events/{event}/edit`, THE Application SHALL redirect the visitor to `/login`.
2. WHEN an unauthenticated visitor sends a POST, PUT, PATCH, or DELETE request to any organizer route, THE Application SHALL reject the request and redirect or return an unauthenticated error response.
3. WHEN an authenticated Organizer sends a request to any of the routes listed in criterion 1, THE Application SHALL serve the requested page without redirecting.
4. THE Application SHALL enforce authentication for all organizer routes via middleware applied at the route definition level.

---

### Requirement 3: Events Database Table

**User Story:** As a developer, I want a well-structured `events` table, so that Event records can be persisted, queried, and safely soft-deleted.

#### Acceptance Criteria

1. THE Application SHALL create an `events` table via a migration containing the columns: `id` (auto-increment primary key), `user_id` (unsigned big integer, foreign key referencing `users.id`, cascades on delete), `uuid` (char 36, unique), `name` (string, max 255), `slug` (string, max 255, unique), `description` (text, nullable), `event_date` (date, nullable), `location` (string, max 255, nullable), `status` (string, default `active`, allowed values: `active`, `archived`), `upload_enabled` (boolean, default `true`), `created_at`, `updated_at`, and `deleted_at` (nullable, for SoftDelete).
2. THE Application SHALL add a non-unique database index on `events.user_id` to support per-Organizer queries.
3. WHEN the migration is rolled back, THE Application SHALL result in the `events` table no longer existing in the database schema.

---

### Requirement 4: Event Model

**User Story:** As a developer, I want a fully configured Event Eloquent model, so that the application can interact with events in a type-safe and consistent way.

#### Acceptance Criteria

1. THE Application SHALL provide an `App\Models\Event` model that uses Laravel's `SoftDeletes` trait.
2. THE Application SHALL declare `uuid`, `name`, `slug`, `description`, `event_date`, `location`, `status`, and `upload_enabled` as mass-assignable on the Event model.
3. THE Application SHALL cast `event_date` to `date`, `upload_enabled` to `boolean`, and `deleted_at` to `datetime` on the Event model.
4. WHEN a new Event is created, THE Application SHALL automatically generate a UUID v4 value and assign it to the `uuid` attribute before the record is persisted.
5. THE Application SHALL define an `Event belongsTo User` relationship on the Event model.
6. THE Application SHALL define a `User hasMany Event` relationship on the User model.
7. THE Application SHALL use the `uuid` attribute (not the `id`) when resolving Event model bindings in routes, by overriding `getRouteKeyName()` to return `'uuid'`.
8. THE Application SHALL restrict the `status` field to the values `active` and `archived`; any other value SHALL be treated as invalid.
9. THE Application SHALL enforce that the `uuid` attribute is unique across all Event records in the database.

---

### Requirement 5: Slug Generation

**User Story:** As an Organizer, I want each event to have a unique, readable URL slug, so that event URLs are clean and do not expose internal database IDs.

#### Acceptance Criteria

1. WHEN an Organizer creates an Event, THE Application SHALL generate a slug from the event name by lowercasing all characters, replacing sequences of non-alphanumeric characters with a single hyphen, and removing any leading or trailing hyphens.
2. WHEN the generated base slug already exists in the `events` table among non-deleted records, THE Application SHALL append a numeric suffix starting at `-2` and incrementing by one until a unique slug is found.
3. THE Application SHALL preserve the original slug when an Organizer updates an Event's name.
4. THE Application SHALL generate a non-empty slug for any valid event name; if the name contains no alphanumeric characters, THE Application SHALL use a fallback slug derived from the event's UUID.
5. FOR ALL valid event names submitted by Organizers, THE slug generated SHALL match the regular expression `^[a-z0-9]+(-[a-z0-9]+)*$` (slug format invariant).

---

### Requirement 6: Event Creation

**User Story:** As an Organizer, I want to create a new event with a name, description, date, and location, so that I can set up events to share with attendees.

#### Acceptance Criteria

1. WHEN an Organizer submits the create event form with valid data, THE EventController SHALL store a new Event record associated with the authenticated Organizer's `user_id`.
2. WHEN a new Event is stored, THE Application SHALL set `status` to `active` and `upload_enabled` to `true` by default.
3. WHEN an Organizer submits the create event form with a missing or empty `name`, THE EventRequest SHALL reject the submission with a validation error message indicating the name field is required.
4. WHEN an Organizer submits the create event form with a `name` longer than 255 characters, THE EventRequest SHALL reject the submission with a validation error message indicating the name field may not exceed 255 characters.
5. WHEN an Organizer submits the create event form with null or empty values for `description`, `event_date`, and `location`, THE Application SHALL proceed with storing the event using null values for those fields.
6. WHEN an Organizer submits a non-null `event_date` that is not a recognizable date string, THE EventRequest SHALL reject the submission with a validation error message indicating the expected date format.
7. WHEN an Organizer submits a non-null `location` longer than 255 characters, THE EventRequest SHALL reject the submission with a validation error message indicating the location field may not exceed 255 characters.
8. WHEN an Event is successfully created, THE Application SHALL redirect the Organizer to the EventShow page for the newly created event.
9. IF the event record cannot be persisted due to a storage failure, THEN THE Application SHALL return an error response to the Organizer and leave no partial event record in the database.
10. WHEN an Organizer submits a `description` longer than 5000 characters, THE EventRequest SHALL reject the submission with a validation error message indicating the description field may not exceed 5000 characters.

---

### Requirement 7: Event Update

**User Story:** As an Organizer, I want to edit an event's details, so that I can correct mistakes or update information.

#### Acceptance Criteria

1. WHEN an Organizer submits the edit event form with valid data, THE EventController SHALL update the `name`, `description`, `event_date`, `location`, and `status` fields of the Event.
2. IF an update request for an Event is processed, THEN THE EventController SHALL NOT change the `id`, `uuid`, `user_id`, or `slug` of the Event.
3. WHEN an Organizer submits the edit event form, THE EventRequest SHALL apply the same validation rules for `name`, `description`, `event_date`, `location`, and `status` as defined in Requirements 6 and the status rule below.
4. IF an Organizer submits an updated `status` value that is not `active` or `archived`, THEN THE EventRequest SHALL reject the submission with a validation error message indicating the allowed status values.
5. WHEN an Event is successfully updated, THE Application SHALL redirect the Organizer to the EventShow page for that event.
6. IF the edit event form is submitted and the EventRequest returns validation errors, THEN THE Events/Edit page SHALL redisplay the form with inline error messages and the previously submitted field values preserved.
7. IF an Organizer submits an update request for an Event they do not own, THEN THE EventController SHALL reject the request with a 403 Forbidden response without modifying the Event.

---

### Requirement 8: Event Soft Delete

**User Story:** As an Organizer, I want to delete an event, so that I can remove events I no longer need without permanently losing data.

#### Acceptance Criteria

1. WHEN an Organizer who owns an Event submits a delete request for that Event, THE EventController SHALL mark the Event as deleted without permanently removing the Event record from the database.
2. WHILE an Event is soft-deleted, THE Application SHALL exclude that Event from all Organizer-facing event listings, detail views, and dashboard queries by default.
3. WHEN an Event is successfully soft-deleted, THE Application SHALL redirect the Organizer to the EventIndex page.
4. IF an Organizer submits a delete request for an Event they do not own, THEN THE EventController SHALL reject the request and return a 403 Forbidden response without modifying any Event record.
5. IF the delete operation fails due to a storage error, THEN THE EventController SHALL return an error message to the Organizer and leave the Event record unchanged.

---

### Requirement 9: Event Authorization

**User Story:** As an Organizer, I want the system to ensure I can only manage my own events, so that no Organizer can view or modify another Organizer's events.

#### Acceptance Criteria

1. THE Application SHALL provide an `App\Policies\EventPolicy` that is registered with Laravel's Gate via automatic model policy discovery or explicit registration in a service provider.
2. WHEN an Organizer requests to view an Event, THE EventPolicy SHALL permit the action if the Event's `user_id` matches the authenticated Organizer's `id`, and SHALL deny the action otherwise.
3. WHEN an Organizer requests to update an Event, THE EventPolicy SHALL permit the action if the Event's `user_id` matches the authenticated Organizer's `id`, and SHALL deny the action otherwise.
4. WHEN an Organizer requests to delete an Event, THE EventPolicy SHALL permit the action if the Event's `user_id` matches the authenticated Organizer's `id`, and SHALL deny the action otherwise.
5. IF an Organizer attempts to view, update, or delete an Event that belongs to a different Organizer, THEN THE Application SHALL return a 403 Forbidden response without modifying any Event data.
6. IF an unauthenticated user attempts to access any event route, THEN THE Application SHALL return a 401 Unauthenticated response or redirect to the login page.
7. THE EventController SHALL authorize every show, edit, update, and destroy action against the EventPolicy before executing any business logic, such that a policy denial prevents all further processing.

---

### Requirement 10: Dashboard Page

**User Story:** As an Organizer, I want a dashboard showing my event statistics, so that I can quickly understand the state of my events.

#### Acceptance Criteria

1. WHEN an authenticated Organizer visits `/dashboard`, THE DashboardController SHALL return to the Inertia `dashboard` page the following statistics scoped to the authenticated Organizer: total event count, count of events with status `active`, count of events with status `archived`, and the five most recently created non-deleted events ordered by `created_at` descending.
2. THE Dashboard page SHALL display each statistic in a labeled card component using the existing `Card`, `CardHeader`, `CardTitle`, and `CardContent` components from `resources/js/components/ui/card.tsx`, with each card's label matching the statistic name (e.g., "Total Events", "Active Events", "Archived Events").
3. THE Dashboard page SHALL display each recent event's name, a status badge whose text reflects the event's status value (`active` or `archived`), and the event's date formatted as a human-readable date string, or the text "No date set" when `event_date` is null.
4. WHEN the Organizer has no events, THE Dashboard page SHALL display a visible text message containing the phrase "no events" (case-insensitive) in place of the recent events list.
5. THE Dashboard page SHALL include a navigable link resolving to the `/events/create` route.

---

### Requirement 11: Event Index Page

**User Story:** As an Organizer, I want to see all my events in one place, so that I can manage them efficiently.

#### Acceptance Criteria

1. WHEN an authenticated Organizer visits `/events`, THE EventController index action SHALL return to the Inertia `Events/Index` page all non-deleted Events belonging to the authenticated Organizer, ordered by `created_at` descending.
2. WHILE the Events/Index page is displaying events, THE EventIndex page SHALL display for each event: event name, event date (or "No date set"), location (or "No location"), a status badge showing the event's status value (`active` or `archived`), an indicator showing whether uploads are enabled or disabled, and created date.
3. THE EventIndex page SHALL display action links for each event: View (links to EventShow), Edit (links to EventEdit), and Delete.
4. WHEN an Organizer clicks the Delete action for an event, THE EventIndex page SHALL display a confirmation prompt before submitting the DELETE request.
5. THE EventIndex page SHALL include a "Create Event" button that links to the EventCreate page at `/events/create`.
6. WHEN the Organizer has no events, THE EventIndex page SHALL display an empty-state message with a link to create the first event.

---

### Requirement 12: Event Show Page

**User Story:** As an Organizer, I want to view the full details of an event, so that I can review all information before sharing it.

#### Acceptance Criteria

1. WHEN an authenticated Organizer visits `/events/{event}`, THE EventController show action SHALL return the Inertia `Events/Show` page with the Event record identified by the UUID route parameter.
2. IF the UUID in the route parameter does not match any existing Event record, THEN THE EventController SHALL return a not-found error response to the Organizer.
3. IF the authenticated Organizer is not the owner of the requested Event, THEN THE EventController SHALL return an authorization error response and SHALL NOT expose the Event data.
4. WHEN the `Events/Show` page loads, THE EventShow page SHALL display the event name, description (displaying "No description" if no description is set), event date (displaying "No date set" if no date is set), location (displaying "No location" if no location is set), status, upload enabled or disabled indicator, and created date.
5. WHEN the `Events/Show` page loads, THE EventShow page SHALL display three labelled sections — "QR Code", "Photo Gallery", and "Uploads" — each containing a visible "Coming Soon" label.
6. WHEN the `Events/Show` page loads, THE EventShow page SHALL display an Edit button and a Delete button for the Event.

---

### Requirement 13: Event Create Form

**User Story:** As an Organizer, I want a form to create a new event, so that I can enter all event details in a structured way.

#### Acceptance Criteria

1. WHEN an authenticated Organizer visits `/events/create`, THE Application SHALL render the Inertia `Events/Create` page containing a form with fields: Name (required text input, maximum 255 characters), Description (optional textarea, maximum 5000 characters), Event Date (optional date input), Location (optional text input, maximum 255 characters).
2. IF the form is submitted and the EventRequest returns validation errors, THEN THE Events/Create page SHALL display inline validation error messages below each invalid field using the existing `InputError` component without clearing previously entered valid field values.
3. THE Events/Create page SHALL use the existing AppLayout with a breadcrumb trail: Dashboard → Events → Create Event.
4. WHEN the form is submitted and the EventRequest passes validation, THE Application SHALL persist the new event and redirect the Organizer to the EventShow page for the newly created event.
5. IF an unauthenticated user visits `/events/create`, THEN THE Application SHALL redirect the user to the login page.

---

### Requirement 14: Event Edit Form

**User Story:** As an Organizer, I want a form to edit an existing event, so that I can update event details after creation.

#### Acceptance Criteria

1. WHEN an authenticated Organizer visits `/events/{event}/edit` for an Event they own, THE Application SHALL render the Inertia `Events/Edit` page with the form pre-populated with the event's current values.
2. THE Events/Edit form SHALL include: Name (required, max 255 characters), Description (optional textarea, max 5000 characters), Event Date (optional date input), Location (optional text input, max 255 characters), and Status (select with options `active` and `archived`, pre-selected to current value).
3. IF the form is submitted and the EventRequest returns validation errors, THEN THE Events/Edit page SHALL display inline validation error messages using the existing `InputError` component and SHALL preserve the submitted field values.
4. THE Events/Edit page SHALL use the existing AppLayout with a breadcrumb trail: Dashboard → Events → {event name} → Edit.
5. WHEN the edit form is submitted with valid data, THE Application SHALL update the Event and redirect the Organizer to the EventShow page.
6. IF an Organizer attempts to visit the edit page for an Event they do not own, THEN THE Application SHALL return a 403 Forbidden response.

---

### Requirement 15: Sidebar Navigation

**User Story:** As an Organizer, I want "Events" to appear in the sidebar navigation, so that I can easily navigate to the events list from any page.

#### Acceptance Criteria

1. THE AppSidebar component SHALL include an "Events" navigation item using the `CalendarDays` Lucide icon, linking to `/events`.
2. WHEN the Organizer is on a page whose URL begins with `/events`, THE AppSidebar SHALL render the "Events" navigation item with an active visual state (e.g., highlighted background or bold text) distinguishable from inactive items.
3. WHEN the Organizer is on a page whose URL does not begin with `/events`, THE AppSidebar SHALL render the "Events" navigation item without the active visual state.

---

### Requirement 16: Wayfinder Route Helpers

**User Story:** As a developer, I want type-safe Wayfinder route helpers for all new event and dashboard routes, so that the frontend always uses correct URLs without string literals.

#### Acceptance Criteria

1. THE Application SHALL maintain Wayfinder-generated route helper files in `resources/js/routes/` that reflect all registered event and dashboard routes, such that the files can be verified to contain helpers for `/events`, `/events/create`, `/events/{event}`, `/events/{event}/edit`, and `/dashboard`.
2. THE EventIndex, EventShow, EventCreate, and EventEdit pages SHALL NOT contain hardcoded URL strings for event or dashboard routes; all route references SHALL use imported Wayfinder-generated helpers.
3. IF a page imports a Wayfinder route helper function that does not exist in the generated route files, THEN the TypeScript compiler SHALL produce a compilation error for that import.

---

### Requirement 17: Event Factory and Tests

**User Story:** As a developer, I want factories and automated tests for the event feature, so that regressions are caught early and the feature is verifiable.

#### Acceptance Criteria

1. THE Application SHALL provide an `EventFactory` in `database/factories/EventFactory.php` that generates Event records with all fields populated: `user_id` (valid user foreign key), `uuid` (unique UUID v4), `name` (random string up to 100 chars), `slug` (unique slug derived from `name`), `description` (random text or null), `event_date` (random future date or null), `location` (random string or null), `status` (one of `active` or `archived`), `upload_enabled` (random boolean).
2. WHEN the EventFactory creates multiple Event records, each record SHALL have a unique `slug` value and a unique `uuid` value, with slug generation following the uniqueness rules defined in Requirement 5.
3. THE Application SHALL include PHPUnit feature tests in `tests/Feature/` covering: (a) a guest sending GET to `/dashboard` is redirected to `/login`; (b) a guest sending GET to `/events`, `/events/create`, `/events/{uuid}`, and `/events/{uuid}/edit` is redirected to `/login`; (c) an authenticated Organizer can POST to `/events` with valid data and receive a redirect response; (d) the created Event record has a `user_id` matching the authenticated Organizer's `id`; (e) a User can own multiple Event records; (f) an authenticated Organizer can PUT to `/events/{uuid}` with valid data and receive a redirect response; (g) after a DELETE to `/events/{uuid}`, the Event record remains in the database with a non-null `deleted_at` and the Organizer is redirected to `/events`; (h) an Organizer sending PUT or DELETE to another Organizer's event receives a 403 response.
4. THE Application SHALL include a test that creates at least 10 Event records via the EventFactory and asserts that every generated slug matches the regular expression `^[a-z0-9]+(-[a-z0-9]+)*$`.
