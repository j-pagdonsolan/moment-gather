# Implementation Plan: MomentGather Foundation

## Overview

Implement the MomentGather foundation on top of the existing Laravel 13 + Inertia.js + React 19 application. The plan covers backend infrastructure (migration, model, service, policy, requests, controllers), route wiring, Wayfinder generation, TypeScript type definitions, five React pages, sidebar navigation, a factory, and a full PHPUnit + property-based test suite.

---

## Tasks

- [x] 1. Application branding
  - [x] 1.1 Update `config/app.php` default app name
    - Change `'name' => env('APP_NAME', 'Laravel')` to `'name' => env('APP_NAME', 'MomentGather')`
    - `app-logo.tsx` already reads `usePage().props.name`, so no logo change is needed
    - _Requirements: 1.1, 1.2, 1.3_
  - [x] 1.2 Update `resources/js/pages/welcome.tsx` heading
    - Import `usePage` from `@inertiajs/react` (already imported) and read `name` from shared props
    - Replace the hard-coded `"Let's get started"` `<h1>` content with `{name}` so the page reflects the configured app name
    - _Requirements: 1.1, 1.3_

- [x] 2. Events database migration
  - [x] 2.1 Create migration file `database/migrations/{timestamp}_create_events_table.php`
    - Table columns: `id` (auto-increment PK), `user_id` (foreignId → `users.id`, cascadeOnDelete, index), `uuid` (char 36, unique), `name` (string 255), `slug` (string 255, unique), `description` (text, nullable), `event_date` (date, nullable), `location` (string 255, nullable), `status` (string 20, default `'active'`), `upload_enabled` (boolean, default `true`), `timestamps()`, `softDeletes()`
    - `down()` method calls `Schema::dropIfExists('events')`
    - _Requirements: 3.1, 3.2, 3.3_

- [x] 3. Event model and User relationship
  - [x] 3.1 Create `app/Models/Event.php`
    - Use PHP 8 `#[Fillable([...])]` attribute (same style as `User.php`) — do NOT use `protected $fillable`
    - Fillable fields: `uuid`, `name`, `slug`, `description`, `event_date`, `location`, `status`, `upload_enabled`
    - Use traits `HasFactory` (with `@use HasFactory<EventFactory>` docblock) and `SoftDeletes`
    - `casts()` method: `event_date → 'date'`, `upload_enabled → 'boolean'`, `deleted_at → 'datetime'`
    - `booted()` static method: on `creating`, if `$event->uuid` is empty, assign `(string) Str::uuid()`
    - `getRouteKeyName()` returns `'uuid'`
    - `user()` method returns `$this->belongsTo(User::class)`
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.7, 4.8, 4.9_
  - [x] 3.2 Add `events()` relationship to `app/Models/User.php`
    - Add `use App\Models\Event;` and `use Illuminate\Database\Eloquent\Relations\HasMany;` imports
    - Add `public function events(): HasMany` returning `$this->hasMany(Event::class)`
    - _Requirements: 4.6_

- [x] 4. SlugGenerator service
  - [x] 4.1 Create `app/Services/SlugGenerator.php`
    - `generate(string $name, ?int $excludeId = null): string` — slugifies the name (lowercase, replace non-alphanumeric sequences with `-`, trim hyphens), then calls `makeUnique()`. Returns empty string if name has no alphanumeric characters.
    - `generateFromUuid(string $uuid): string` — derives a base from the first 12 hex characters of the UUID (strip hyphens) then calls `makeUnique()`
    - `slugify(string $name): string` (private) — pure transformation with no DB access
    - `makeUnique(string $base, ?int $excludeId): string` (private) — queries `Event::withoutTrashed()->where('slug', $slug)`, optionally excluding by `id`; appends `-2`, `-3`, … until unique
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

- [x] 5. EventPolicy
  - [x] 5.1 Create `app/Policies/EventPolicy.php`
    - Methods: `view(User $user, Event $event): bool`, `update(User $user, Event $event): bool`, `delete(User $user, Event $event): bool`
    - Each returns `$user->id === $event->user_id`
    - No `create` method (creation is scoped at the controller level)
    - Policy discovery is automatic in Laravel 13; no manual registration needed
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

- [x] 6. Form Requests
  - [x] 6.1 Create `app/Http/Requests/StoreEventRequest.php`
    - `authorize()` returns `true`
    - `rules()`: `name` → `['required', 'string', 'max:255']`, `description` → `['nullable', 'string', 'max:5000']`, `event_date` → `['nullable', 'date']`, `location` → `['nullable', 'string', 'max:255']`
    - `status` and `upload_enabled` are NOT in this request (set by controller)
    - _Requirements: 6.3, 6.4, 6.5, 6.6, 6.7, 6.10_
  - [x] 6.2 Create `app/Http/Requests/UpdateEventRequest.php`
    - `authorize()` returns `true`
    - `rules()`: same as StoreEventRequest plus `status` → `['required', Rule::in(['active', 'archived'])]`
    - _Requirements: 7.3, 7.4_

- [x] 7. DashboardController
  - [x] 7.1 Create `app/Http/Controllers/DashboardController.php`
    - `index(Request $request): Response`
    - Compute `$stats`: `totalEvents`, `activeEvents`, `archivedEvents` — all scoped to `$request->user()->id`, excluding soft-deleted rows (Eloquent default)
    - Fetch `$recentEvents`: 5 most recent non-deleted events ordered by `created_at` desc, selecting only `uuid`, `name`, `status`, `event_date`, `created_at`
    - Return `Inertia::render('dashboard', ['stats' => $stats, 'recentEvents' => $recentEvents])`
    - _Requirements: 10.1_

- [x] 8. EventController
  - [x] 8.1 Create `app/Http/Controllers/EventController.php`
    - Constructor: inject `private SlugGenerator $slugGenerator`
    - `index`: return all non-deleted events for authenticated user, ordered by `created_at` desc → `Inertia::render('Events/Index', ['events' => $events])`
    - `create`: return `Inertia::render('Events/Create')`
    - `store(StoreEventRequest $request)`: wrap entire body in `DB::transaction()`; generate UUID, generate slug via `$this->slugGenerator->generate($data['name'])` with UUID fallback; call `$request->user()->events()->create([...$data, 'uuid' => $uuid, 'slug' => $slug, 'status' => 'active', 'upload_enabled' => true])`; redirect to `events.show`
    - `show(Request $request, Event $event)`: call `$this->authorize('view', $event)`; return `Inertia::render('Events/Show', ['event' => $event])`
    - `edit(Request $request, Event $event)`: call `$this->authorize('update', $event)`; return `Inertia::render('Events/Edit', ['event' => $event])`
    - `update(UpdateEventRequest $request, Event $event)`: call `$this->authorize('update', $event)`; call `$event->update($request->validated())`; redirect to `events.show`
    - `destroy(Request $request, Event $event)`: call `$this->authorize('delete', $event)`; call `$event->delete()`; redirect to `events.index`
    - _Requirements: 6.1, 6.2, 6.8, 6.9, 7.1, 7.2, 7.5, 7.7, 8.1, 8.3, 8.4, 9.7, 11.1, 12.1_

- [x] 9. Update routes/web.php
  - [x] 9.1 Replace inline dashboard route and add event resource routes
    - Replace `Route::inertia('dashboard', 'dashboard')->name('dashboard')` with `Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard')`
    - Add `Route::resource('events', EventController::class)` with `->except(['index'])` and named routes for `create`, `store`, `show`, `edit`, `update`, `destroy`
    - Add separate `Route::get('events', [EventController::class, 'index'])->name('events.index')` inside the auth middleware group
    - Add imports for `DashboardController` and `EventController`
    - Final route table: GET `/dashboard`, GET/POST `/events`, GET `/events/create`, GET `/events/{event}`, GET `/events/{event}/edit`, PUT/PATCH `/events/{event}`, DELETE `/events/{event}`
    - _Requirements: 2.1, 2.4, 16.1_

- [x] 10. Generate Wayfinder route helpers
  - [x] 10.1 Run `php artisan wayfinder:generate` to regenerate `resources/js/routes/index.ts`
    - After generation, verify the file contains helpers: `eventsIndex`, `eventsCreate`, `eventsStore`, `eventsShow`, `eventsEdit`, `eventsUpdate`, `eventsDestroy`, and `dashboard`
    - _Requirements: 16.1, 16.2, 16.3_

- [x] 11. TypeScript type definitions
  - [x] 11.1 Create `resources/js/types/models.ts`
    - Export `EventStatus = 'active' | 'archived'`
    - Export `interface Event` with fields: `id: number`, `uuid: string`, `user_id: number`, `name: string`, `slug: string`, `description: string | null`, `event_date: string | null`, `location: string | null`, `status: EventStatus`, `upload_enabled: boolean`, `created_at: string`, `updated_at: string`, `deleted_at: string | null`
    - Export `interface DashboardStats` with fields: `totalEvents: number`, `activeEvents: number`, `archivedEvents: number`
    - _Requirements: 10.2, 11.2, 12.4_
  - [x] 11.2 Re-export from `resources/js/types/index.ts`
    - Add `export type * from './models';` to the existing file
    - _Requirements: 16.2_

- [x] 12. Update app-sidebar.tsx
  - [x] 12.1 Add Events navigation item with active-state detection
    - Move `mainNavItems` array inside the `AppSidebar` component function body (needed to access hook)
    - Import `CalendarDays` from `lucide-react` alongside existing `LayoutGrid`
    - Import `eventsIndex` from `@/routes`
    - Import `useCurrentUrl` hook (check `resources/js/hooks/` for existing hook; use `isCurrentOrParentUrl`)
    - Add Events item: `{ title: 'Events', href: eventsIndex(), icon: CalendarDays, isActive: isCurrentOrParentUrl(eventsIndex()) }`
    - Remove the static `footerNavItems` links to the Laravel repository and Laracasts — these are starter-kit placeholders not appropriate for MomentGather
    - _Requirements: 15.1, 15.2, 15.3_

- [x] 13. Dashboard page
  - [x] 13.1 Rewrite `resources/js/pages/dashboard.tsx`
    - Props interface: `{ stats: DashboardStats; recentEvents: Pick<Event, 'uuid' | 'name' | 'status' | 'event_date' | 'created_at'>[] }`
    - Three stat cards using `Card`, `CardHeader`, `CardTitle`, `CardContent` from `@/components/ui/card` — labeled "Total Events", "Active Events", "Archived Events"
    - Recent events list: each item shows `name`, a `Badge` with the status value, and `event_date` formatted via `toLocaleDateString()` or `"No date set"` when null
    - Empty state: visible text containing the phrase "no events" and a `Link` to `eventsCreate()`
    - "Create Event" `Link` pointing to `eventsCreate()` visible at all times
    - No hardcoded URLs — all routes via Wayfinder helpers
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

- [x] 14. Events/Index page
  - [x] 14.1 Create `resources/js/pages/Events/Index.tsx`
    - Props: `{ events: Event[] }`
    - Table/list with columns: name, event date (`event_date` formatted or `"No date set"`), location (`location` or `"No location"`), status `Badge`, upload enabled indicator, created date
    - Action links per row: View → `eventsShow({ event: e.uuid })`, Edit → `eventsEdit({ event: e.uuid })`, Delete (opens shadcn/ui `Dialog` confirmation before submitting `router.delete(eventsDestroy({ event: e.uuid }))`)
    - "Create Event" button linking to `eventsCreate()`
    - Empty state with link to `eventsCreate()` when `events.length === 0`
    - Breadcrumbs: Dashboard → Events
    - No hardcoded URL strings
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6_

- [x] 15. Events/Create page
  - [x] 15.1 Create `resources/js/pages/Events/Create.tsx`
    - Use `useForm` from `@inertiajs/react` with initial state `{ name: '', description: '', event_date: '', location: '' }`
    - `submit` handler calls `post(eventsStore())`
    - Fields: Name (required text input, max 255), Description (optional textarea, max 5000), Event Date (optional date input), Location (optional text input, max 255)
    - Each field followed by `<InputError message={errors.fieldName} />` from `@/components/input-error`
    - Use `AppLayout` via `Create.layout` with breadcrumbs: Dashboard → Events → Create Event
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_

- [x] 16. Events/Edit page
  - [x] 16.1 Create `resources/js/pages/Events/Edit.tsx`
    - Props: `{ event: Event }`
    - Use `useForm` pre-populated: `{ name: event.name, description: event.description ?? '', event_date: event.event_date ?? '', location: event.location ?? '', status: event.status }`
    - `submit` handler calls `put(eventsUpdate({ event: event.uuid }))`
    - Fields: same as Create plus Status (`<select>` with options `active` / `archived`, pre-selected to current value)
    - Each field followed by `<InputError />`
    - Use function-form of `.layout` to access `event.name` for the breadcrumb: Dashboard → Events → {event.name} → Edit
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6_

- [x] 17. Events/Show page
  - [x] 17.1 Create `resources/js/pages/Events/Show.tsx`
    - Props: `{ event: Event }`
    - Display: name (`<Head title={event.name} />`), description (or `"No description"`), event date (formatted or `"No date set"`), location (or `"No location"`), status `Badge`, upload enabled/disabled indicator, created date
    - Edit button linking to `eventsEdit({ event: event.uuid })`
    - Delete button opening shadcn/ui `Dialog` confirmation; on confirm calls `router.delete(eventsDestroy({ event: event.uuid }), { onSuccess: () => router.visit(eventsIndex()) })`
    - Three labeled "Coming Soon" sections: "QR Code", "Photo Gallery", "Uploads"
    - Use function-form of `.layout` for breadcrumbs: Dashboard → Events → {event.name}
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6_

- [x] 18. Checkpoint — backend wired end-to-end
  - Run `php artisan migrate` to apply the events migration
  - Run `php artisan route:list | findstr events` to confirm all 7 event routes and the dashboard route are registered
  - Ensure all tests pass, ask the user if questions arise.

- [x] 19. EventFactory
  - [x] 19.1 Create `database/factories/EventFactory.php`
    - `$model = Event::class`
    - `definition()`: generate `$name` via `$this->faker->sentence(3)` and `$uuid` via `Str::uuid()`; resolve `SlugGenerator` via `app(SlugGenerator::class)`; compute `$slug` (use UUID fallback if `generate()` returns empty string)
    - Fields: `user_id` → `User::factory()`, `uuid`, `name`, `slug`, `description` → `$this->faker->optional()->paragraph()`, `event_date` → `$this->faker->optional()->dateTimeBetween('now', '+2 years')?->format('Y-m-d')`, `location` → `$this->faker->optional()->city()`, `status` → `$this->faker->randomElement(['active', 'archived'])`, `upload_enabled` → `$this->faker->boolean()`
    - Slug uniqueness is guaranteed by `SlugGenerator::makeUnique()` querying the DB on each factory call
    - _Requirements: 17.1, 17.2_

- [x] 20. PHPUnit feature tests
  - [x] 20.1 Create `tests/Feature/EventTest.php`
    - Test (a): guest GET `/dashboard` redirects to `/login`
    - Test (b): guest GET `/events`, `/events/create`, `/events/{uuid}`, `/events/{uuid}/edit` all redirect to `/login` — use `#[DataProvider]`
    - Test (c): authenticated user POST `/events` with `['name' => 'My Event']` returns redirect
    - Test (d): created event has `user_id` matching the authenticated user
    - Test (e): user can own multiple events — create 3 via factory, assert `$user->events()->count() === 3`
    - Test (f): authenticated user PUT `/events/{uuid}` with valid data returns redirect and event name is updated
    - Test (g): DELETE `/events/{uuid}` soft-deletes the event (`assertSoftDeleted`) and redirects to `/events`
    - Test (h): PUT and DELETE to another user's event both return 403
    - _Requirements: 17.3_
  - [x] 20.2 Create `tests/Feature/DashboardTest.php`
    - Test: authenticated organizer GET `/dashboard` returns Inertia page with correct `stats` and `recentEvents` props
    - Verify soft-deleted events are excluded from stats
    - _Requirements: 10.1, 17.3_

- [x] 21. Property-based tests
  - [x] 21.1 Create `tests/Feature/SlugGeneratorTest.php`
    - **Property 1: Slug format invariant** — loop 100 iterations generating random names that contain at least one alphanumeric character, assert each slug matches `/^[a-z0-9]+(-[a-z0-9]+)*$/`
      - **Validates: Requirements 5.1, 5.5, 17.4**
    - **Property 2: Slug uniqueness** — create 10 events via `Event::factory()->count(10)->for($user)->create()`; assert all slugs are unique and each matches the format regex
      - **Validates: Requirements 5.2, 17.2**
  - [ ]* 21.2 Write property test for immutable identity fields on update
    - **Property 4: Immutable identity fields on update** — loop 50 iterations; for each: create event, snapshot `['id','uuid','user_id','slug']`, submit valid PUT request, assert snapshot equals `$event->fresh()->only([...])`
    - **Validates: Requirements 7.2**
  - [ ]* 21.3 Write property test for dashboard statistics accuracy
    - **Property 9: Dashboard statistics accuracy** — loop 20 iterations with random counts of active, archived, and soft-deleted events; GET `/dashboard`; assert `stats.totalEvents`, `stats.activeEvents`, `stats.archivedEvents` match expected values
    - **Validates: Requirements 10.1**

- [x] 22. Run tests and fix failures
  - [x] 22.1 Run `php artisan test` and resolve any failures
    - Address migration order issues, missing imports, or policy registration gaps
    - Re-run until all tests pass with no errors or warnings
    - _Requirements: 17.3, 17.4_

---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP; core test tasks (20.1, 20.2, 21.1) are not optional
- PHP 8 `#[Fillable]` attribute syntax is required — do not use `protected $fillable = []`
- `EventController::store()` must be wrapped in `DB::transaction()` (Requirement 6.9)
- Slug is never regenerated on update; the `excludeId` parameter in `SlugGenerator::generate()` is unused in the update path
- Wayfinder route names follow the convention: `events.index` → `eventsIndex`, `events.show` → `eventsShow`, etc.
- The `{event}` route parameter resolves via UUID because `Event::getRouteKeyName()` returns `'uuid'`
- All frontend route references must use imported Wayfinder helpers — no hardcoded URL strings
- Delete confirmations on EventIndex and EventShow must use a shadcn/ui `Dialog`, not `window.confirm`
- The `.layout` property on EventEdit and EventShow must be the function form `(page, props) => ({...})` to access the event name for dynamic breadcrumbs

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "2.1"] },
    { "id": 1, "tasks": ["3.1", "3.2", "4.1"] },
    { "id": 2, "tasks": ["5.1", "6.1", "6.2", "7.1"] },
    { "id": 3, "tasks": ["8.1"] },
    { "id": 4, "tasks": ["9.1"] },
    { "id": 5, "tasks": ["10.1"] },
    { "id": 6, "tasks": ["11.1", "11.2"] },
    { "id": 7, "tasks": ["12.1", "13.1"] },
    { "id": 8, "tasks": ["14.1", "15.1", "16.1", "17.1"] },
    { "id": 9, "tasks": ["19.1"] },
    { "id": 10, "tasks": ["20.1", "20.2", "21.1"] },
    { "id": 11, "tasks": ["21.2", "21.3", "22.1"] }
  ]
}
```
