# Implementation Plan: Public Event Page

## Overview

This plan implements the attendee-facing public event page served at `/e/{slug}`. The feature is small and read-only: one dedicated controller, one route, one optional TypeScript type, one standalone React page, and one feature test file. There are no migrations, no `Event` model changes, and no changes to organizer code.

The tasks are ordered for incremental, integrated progress: the controller and TypeScript type come first (independent), the route and React page build on the controller, the feature tests exercise the full route → controller → Inertia-render path, and a final verification checkpoint ties everything together and confirms nothing is left orphaned.

Because the design's Testing Strategy applies **example-based feature tests** (one per Requirement 11 clause) rather than property-based testing, there are no property-test sub-tasks. Each feature test is annotated with the design property it validates for traceability.

## Tasks

- [ ] 1. Create the PublicEventController
  - Create `app/Http/Controllers/PublicEventController.php` extending the existing base `Controller`.
  - Add a single `show(string $slug): Response` method that resolves the event with `Event::query()->where('slug', $slug)->where('status', 'active')->firstOrFail()` so nonexistent slugs, non-active statuses, and (via the `SoftDeletes` global scope) soft-deleted rows all resolve to a 404.
  - Build a narrow, hand-assembled payload array with only `name`, `description`, `event_date` (via `$event->event_date?->toDateString()`), `location`, and `upload_enabled` — never `$event->toArray()`, so organizer-private fields cannot leak.
  - Return `Inertia::render('Public/Event', ['event' => $payload])`. Do not read any request query parameters.
  - _Requirements: 1.3, 1.4, 2.1, 2.2, 2.3, 2.4, 2.5, 3.1, 3.2, 3.3, 3.4, 7.4, 10.1, 10.2, 10.3_

- [ ] 2. Add the PublicEvent TypeScript type
  - Add a `PublicEvent` interface to `resources/js/types/models.ts` with `name: string`, `description: string | null`, `event_date: string | null`, `location: string | null`, and `upload_enabled: boolean`, kept distinct from the full `Event` interface.
  - _Requirements: 7.4_

- [ ] 3. Register the public event route
  - In `routes/web.php`, add `use App\Http\Controllers\PublicEventController;` to the imports.
  - Add `Route::get('/e/{slug}', [PublicEventController::class, 'show'])->name('public.events.show');` **outside** the `auth`/`verified` middleware group, after the `home` route, using a plain `{slug}` string parameter (not `{event}`) so the model's uuid route-key binding does not apply.
  - Leave all organizer routes untouched.
  - _Requirements: 1.1, 1.2, 1.5_

- [ ] 4. Build the public React page
  - Create `resources/js/pages/Public/Event.tsx` as a standalone mobile-first page (like `welcome.tsx`) that does NOT use `AppLayout`/sidebar chrome.
  - Set `<Head title={`${event.name} | MomentGather`} />`, render the primary heading "Share Your Moments", and render a static `Cover_Placeholder` region with no image data.
  - Render the event name; render a friendly date via `toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })` only when `event_date` is present; render description and location only when present.
  - When `upload_enabled` is true, render the "Upload Photos" `Button` (placeholder); otherwise render the "Photo uploads are currently closed." message in its place. Always render the secondary "View Gallery" `Button` (placeholder). Both buttons are non-functional. Use the shadcn/ui `Button` from `@/components/ui/button`.
  - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3, 6.4, 6.5, 7.1, 7.2, 7.3, 7.5, 8.1, 8.2, 9.1, 9.2_

- [ ] 5. Write the public event page feature tests
  - [ ] 5.1 Create the test class and visibility/access tests
    - Create `tests/Feature/PublicEventPageTest.php` using `RefreshDatabase`, `Inertia\Testing\AssertableInertia`, and PHPUnit `#[Test]` attributes.
    - `active_event_is_accessible_by_slug` — active event returns 200 with component `Public/Event` and `event.name`. **Validates: Property 1, Property 2.**
    - `nonexistent_slug_returns_404` — unknown slug returns 404. **Validates: Property 1.**
    - `non_active_status_draft_case_returns_404` — a `'draft'` status event returns 404. **Validates: Property 1.**
    - `archived_event_returns_404` — archived event returns 404. **Validates: Property 1.**
    - `soft_deleted_event_returns_404` — soft-deleted event returns 404. **Validates: Property 1.**
    - `public_page_is_reachable_without_authentication` — guest (no `actingAs`) gets 200. **Validates: Property 1.**
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6_

  - [ ] 5.2 Add payload content, upload-flag, and privacy tests
    - `payload_contains_name_and_present_optional_fields` — asserts component `Public/Event` and present `event.name`, `event.description`, `event.event_date`, `event.location`. **Validates: Property 2, Property 5.**
    - `upload_cta_shown_when_upload_enabled_true` — `event.upload_enabled` is `true` in the payload. **Validates: Property 4.**
    - `uploads_closed_when_upload_enabled_false` — `event.upload_enabled` is `false` in the payload. **Validates: Property 4.**
    - `payload_excludes_organizer_private_data` — payload is `missing` `event.id`, `event.uuid`, `event.user_id`, `event.slug`, `event.email`, and `event.user`. **Validates: Property 3.**
    - _Requirements: 11.7, 11.8, 11.9, 11.10_

- [ ] 6. Verification checkpoint
  - Run `php artisan test --filter=PublicEventPageTest` (in-memory SQLite per `phpunit.xml`, no MySQL required) and confirm all tests pass.
  - Run `npx tsc --noEmit` and confirm the frontend type-checks.
  - Run `php artisan route:list` and confirm the `public.events.show` route is registered at `/e/{slug}` outside the auth group.
  - Fix any failures. Ensure all tests pass, ask the user if questions arise.
  - _Requirements: all_

## Notes

- The controller (task 1) and the TypeScript type (task 2) are independent and can be built in parallel first.
- The route (task 3) and the React page (task 4) both depend on the controller existing and the Inertia page name `Public/Event` being agreed; the route references the controller class and the page renders the payload shape.
- The feature tests (task 5) depend on the route, controller, and page being in place so the full HTTP → render path can be exercised.
- No property-based tests are included: the design's Testing Strategy uses example-based feature tests, one per Requirement 11 clause. Each test is annotated with the correctness property it validates for traceability back to the design.
- No migrations, no `Event` model changes, and no organizer code changes are performed by any task, per Requirement 12 scope exclusions.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "2"] },
    { "id": 1, "tasks": ["3", "4"] },
    { "id": 2, "tasks": ["5.1", "5.2"] }
  ]
}
```
