# Implementation Plan: Admin Roles, Permissions & Administration (Phase 13)

## Overview

Strictly additive implementation on top of Phases 1–12 (252 passing tests). Introduces role-based access control, user status enforcement, a full admin area with 8 management sections, plan overrides, and an append-only audit log.

Schema and domain models land first, then services and middleware, then routes, then controllers, then frontend, then factories and tests, then docs.

No existing organizer route, controller, model, or test is removed or replaced.

---

## Tasks

- [ ] 1. Schema migrations and seed data (Wave 0 — all independent)

  - [ ] 1.1 Create migration `add_is_active_to_users_table`
    - Add `is_active` boolean column, default `true`, to the `users` table
    - Run `php artisan migrate` on the dev database after writing the migration
    - _Requirements: R2.1, R27_

  - [ ] 1.2 Create migration `create_roles_table`
    - Columns: `id` (PK auto-increment), `name` varchar(50) unique, `display_name` varchar(100), `created_at`, `updated_at`
    - Add unique index on `name`
    - _Requirements: R1.1, R27.1_

  - [ ] 1.3 Create migration `create_user_roles_table`
    - Columns: `user_id` (FK → `users.id` CASCADE DELETE), `role_id` (FK → `roles.id` CASCADE DELETE)
    - Composite primary key `(user_id, role_id)`; additional index on `user_id`
    - Migration timestamp must be after `create_roles_table` (FK dependency)
    - _Requirements: R1.2, R27.2, R27.3, R27.7_

  - [ ] 1.4 Create migration `create_plan_overrides_table`
    - Columns: `id` (PK), `slug` varchar(50) unique, `max_active_events` int unsigned nullable, `max_photos_per_event` int unsigned nullable, `max_storage_bytes` bigint unsigned nullable, `price` int unsigned nullable, `is_active` boolean default `true`, `created_at`, `updated_at`
    - No FK to other tables (slugs are config strings)
    - _Requirements: R15.1, R27.4_

  - [ ] 1.5 Create migration `create_audit_logs_table`
    - Columns: `id` (PK), `user_id` nullable bigint unsigned FK → `users.id` SET NULL on delete, `action` varchar(100), `target_type` varchar(100) nullable, `target_id` bigint unsigned nullable, `description` text, `metadata` json nullable, `ip_address` varchar(45) nullable, `user_agent` text nullable, `created_at` timestamp only (NO `updated_at`)
    - Add index on `action`; add index on `created_at`
    - The migration adds `created_at` manually (no `$table->timestamps()`)
    - _Requirements: R18.1, R27.5, R27.7_

  - [ ] 1.6 Create `database/seeders/RolesSeeder.php` and wire into `DatabaseSeeder`
    - Use `Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin'])`
    - Use `Role::firstOrCreate(['name' => 'organizer'],   ['display_name' => 'Organizer'])`
    - Register in `DatabaseSeeder::run()` before any user-role seeds
    - Run `php artisan db:seed --class=RolesSeeder` on the dev database after writing the seeder
    - _Requirements: R1.3, R27.6_

- [ ] 2. Domain models and billing helpers (Wave 1 — depend on Wave 0 schema)

  - [ ] 2.1 Create `app/Models/Role.php`
    - `#[Fillable]` for `name` and `display_name`
    - `belongsToMany(User::class, 'user_roles')` relationship
    - _Requirements: R1.1–R1.3, R27.1_

  - [ ] 2.2 Create `app/Models/PlanOverride.php`
    - `#[Fillable]` for all limit columns and `slug`, `is_active`
    - Casts: `max_active_events`, `max_photos_per_event` → `integer` (nullable); `max_storage_bytes` → `integer` (nullable bigint); `price` → `integer` (nullable); `is_active` → `boolean`
    - Unique slug handled at DB level
    - _Requirements: R15.1, R27.4_

  - [ ] 2.3 Create `app/Models/AuditLog.php`
    - `public $timestamps = false`
    - Manually set `created_at` on `AuditLog::create([..., 'created_at' => now()])`
    - Fillable: `user_id`, `action`, `target_type`, `target_id`, `description`, `metadata`, `ip_address`, `user_agent`, `created_at`
    - Cast `metadata` → `array`; cast `created_at` → `datetime`
    - No `update()` or `delete()` calls ever issued in application code
    - _Requirements: R18.1, R18.4, R27.5_

  - [ ] 2.4 Modify `app/Models/User.php` — add role + status helpers
    - Add `roles()` `belongsToMany(Role::class, 'user_roles')` relationship
    - Add `hasRole(string $slug): bool` using `$this->roles->contains('name', $slug)` (eager-safe)
    - Add `isAdmin(): bool` returning `$this->hasRole('super_admin')`
    - Add `isActive(): bool` returning `(bool) $this->is_active`
    - Add `'is_active' => 'boolean'` to the `casts()` method
    - Do NOT add `is_active` to `#[Fillable]`; set it only via explicit `->update()` calls in admin controllers
    - _Requirements: R1.4–R1.6, R2.2, R24_

  - [ ] 2.5 Modify `app/Billing/Plan.php` — add `fromEffectiveConfig()`
    - Add `public static function fromEffectiveConfig(string $slug): self`
    - Step 1: load base — `$data = config('plans')[$slug] ?? config('plans')['free']`
    - Step 2: query `PlanOverride::where('slug', $slug)->first()`
    - Step 3: for each of `max_active_events`, `max_photos_per_event`, `max_storage_bytes`, `price` — use override value only when override row exists AND field is non-null; otherwise use config value
    - Step 4: construct and return `Plan` with merged values
    - `fromConfig()` must remain unchanged (backward compatible)
    - _Requirements: R15.2, R29.3_

  - [ ] 2.6 Modify `app/Billing/BillingService.php` — use `fromEffectiveConfig()`
    - In `currentPlan()`, replace `Plan::fromConfig('pro')` / `Plan::fromConfig('free')` with `Plan::fromEffectiveConfig('pro')` / `Plan::fromEffectiveConfig('free')`
    - All other methods unchanged; existing Phase 1–12 billing tests continue to pass because `fromEffectiveConfig` falls back to config when `plan_overrides` is empty
    - _Requirements: R15.2, R15.9, R29.3_

- [ ] 3. Services, middleware, authorization, and Artisan command (Wave 2 — depend on Wave 1)

  - [ ] 3.1 Create `app/Services/AuditLogger.php`
    - Method: `log(User|null $actor, string $action, string $targetType, int|null $targetId, string $description, array $metadata = []): AuditLog`
    - Reads `ip_address` from `request()->ip()` and `user_agent` from `request()->userAgent()`
    - Creates `AuditLog` record with `created_at = now()`
    - `metadata` must NEVER contain passwords, secrets, `secret_key`, `webhook_secret`, or raw storage paths
    - Audited actions: `user_activated`, `user_deactivated`, `role_assigned`, `role_removed`, `event_archived`, `event_deleted`, `photo_deleted`, `plan_limit_updated`, `plan_deactivated`
    - _Requirements: R18.2–R18.3, R18.5_

  - [ ] 3.2 Create `app/Services/PhotoRemovalService.php`
    - Method: `remove(Photo $photo): void`
    - Step 1: soft-delete the record via `$photo->delete()`
    - Step 2: collect non-null paths from `$photo->original_path`, `$photo->optimized_path`, `$photo->thumbnail_path`
    - Step 3: delete them via `Storage::disk('public')->delete($paths)` (mirrors `ProcessPhoto::failed()` pattern)
    - _Requirements: R14.6, R25.4, Design §PhotoRemovalService_

  - [ ] 3.3 Create `app/Http/Middleware/EnsureSuperAdmin.php`
    - Check `$request->user()?->isAdmin() && $request->user()?->isActive()`; `abort(403)` if either fails
    - No stack trace or internals forwarded to the client
    - _Requirements: R5.1, R5.4, R5.6, R5.7_

  - [ ] 3.4 Create `app/Http/Middleware/EnsureActiveUser.php`
    - Check `$request->user() && !$request->user()->isActive()`; `abort(403)` if true
    - Applied to the organizer route group, not the admin group
    - _Requirements: R11.1–R11.2, R11.4_

  - [ ] 3.5 Modify `app/Providers/AppServiceProvider.php` — add Gates
    - Add `Gate::before(function (User $user, string $ability): ?bool { return $user->isAdmin() ? true : null; })`
    - Add seven named Gate definitions checking `$user->isAdmin()`: `manage-users`, `manage-events`, `manage-photos`, `manage-plans`, `manage-subscriptions`, `view-payments`, `view-audit-logs`
    - Import `Illuminate\Support\Facades\Gate` and `App\Models\User`
    - _Requirements: R4.1, R4.3–R4.4_

  - [ ] 3.6 Modify `app/Policies/EventPolicy.php` — add `before()` method
    - Add `public function before(User $user, string $ability): ?bool { return $user->isAdmin() ? true : null; }`
    - Existing `view()`, `update()`, `delete()` methods unchanged
    - _Requirements: R4.2, R13.1–R13.2_

  - [ ] 3.7 Modify `app/Providers/FortifyServiceProvider.php` — add `is_active` login check
    - Add `Fortify::authenticateUsing(function (Request $request) { ... })` callback
    - Return `null` when user not found, password mismatch, or `!$user->isActive()`; return `$user` otherwise
    - Import `Illuminate\Http\Request`, `App\Models\User`, `Illuminate\Support\Facades\Hash`
    - _Requirements: R2.4, R29.5_

  - [ ] 3.8 Create `app/Console/Commands/CreateAdminUser.php`
    - Signature: `admin:create-admin`
    - Namespace: `App\Console\Commands`
    - Interactive prompts: email (validated with `filter_var(FILTER_VALIDATE_EMAIL)`), name, password (hidden via `secret()`), password confirmation (hidden via `secret()`)
    - Existing email: assign `super_admin` role via `$user->roles()->syncWithoutDetaching([$role->id])`, display confirmation; do NOT change password or other attributes
    - New email, passwords match: create `User` (hashed password), `is_active = true`, assign `super_admin` role, display confirmation
    - New email, passwords mismatch: display error, `return 1`, create nothing
    - Invalid email format: display error, `return 1`, create nothing
    - NEVER print plaintext password in any output
    - CLI-only; no HTTP endpoint
    - _Requirements: R3.1–R3.9_

- [ ] 4. Routes, bootstrap alias, and Inertia share (Wave 3 — depend on Wave 2)

  - [ ] 4.1 Create `routes/admin.php`
    - All routes under `Route::middleware(['auth','verified','admin'])->prefix('admin')->name('admin.')→group(...)`
    - Dashboard: `GET /` → `Admin\DashboardController@index` → `admin.dashboard`
    - Users: `GET users`, `GET users/{user}`, `POST users/{user}/activate`, `POST users/{user}/deactivate`, `POST users/{user}/roles/assign`, `POST users/{user}/roles/remove`
    - Events: `GET events`, `GET events/{event:uuid}`, `POST events/{event:uuid}/archive`, `DELETE events/{event:uuid}`
    - Photos: `GET photos`, `GET photos/{photo:uuid}`, `DELETE photos/{photo:uuid}`
    - Plans: `GET plans`, `PUT plans/{slug}`, `POST plans/{slug}/deactivate`
    - Subscriptions (read-only): `GET subscriptions`, `GET subscriptions/{subscription}`
    - Payments (read-only): `GET payments`, `GET payments/{payment}`
    - Audit Logs (read-only, no DELETE): `GET audit-logs`, `GET audit-logs/{log}`
    - All controller references namespaced under `App\Http\Controllers\Admin\`
    - _Requirements: R5.5, R18.4_

  - [ ] 4.2 Modify `routes/web.php` — load admin routes and apply active middleware
    - Add `require __DIR__.'/admin.php';` at the bottom of the file
    - Apply `EnsureActiveUser` (alias `'active'`) to the existing `['auth','verified']` organizer middleware group via `->middleware('active')`
    - _Requirements: R5.5, R11.1_

  - [ ] 4.3 Modify `bootstrap/app.php` — register middleware aliases
    - In `->withMiddleware(...)`, add: `$middleware->alias(['admin' => \App\Http\Middleware\EnsureSuperAdmin::class, 'active' => \App\Http\Middleware\EnsureActiveUser::class])`
    - _Requirements: R5.5, R11.2_

  - [ ] 4.4 Modify `app/Http/Middleware/HandleInertiaRequests.php` — extend `share()`
    - In the `auth` sub-array of `share()`, add:
      - `'isAdmin' => $request->user()?->isAdmin() ?? false`
      - `'roles'   => $request->user()?->roles->pluck('name')->toArray() ?? []`
    - Never include billing secrets, password hashes, or sensitive fields
    - _Requirements: R22.1, R6.2_

- [ ] 5. Admin controllers (Wave 4 — depend on Wave 3 routes + Wave 2 services + Wave 1 models)

  - [ ] 5.1 Create `app/Http/Controllers/Admin/DashboardController.php`
    - Single `index()` action; `Gate::authorize('manage-users')` (or equivalent admin gate) at top
    - Compute all stats using aggregate COUNT/GROUP BY queries — no N+1:
      - Users: total, active (`is_active=true`), inactive, super_admin count (via `whereHas`)
      - Events: total (non-soft-deleted), by status (`active`/`draft`/`archived`)
      - Photos: total (non-soft-deleted), by status (`pending`/`processing`/`ready`/`failed`)
      - Subscriptions: free-plan users count, pro-plan users count, active/canceled status counts
      - Payments: succeeded count, failed count, 5 most recent (user name/email, amount, currency, status, date)
    - Return `Inertia::render('Admin/Dashboard', $stats)` — shape matches `AdminDashboardStats` type
    - _Requirements: R7, R20.6_

  - [ ] 5.2 Create `app/Http/Controllers/Admin/UserController.php`
    - `Gate::authorize('manage-users')` at top of every action
    - `index()`: paginate 15, search `name`/`email` case-insensitive, filter by role slug and `is_active`; each record: id, name, email, is_active, role names, plan, event count, created_at; never expose password/secrets
    - `show($user)`: full detail per R8.5; load roles, subscription, event count, photo count, storage used, 2FA status; never expose R8.6 forbidden fields
    - `activate($user)`: `$user->update(['is_active' => true])`, AuditLogger `user_activated`, redirect back with toast
    - `deactivate($user)`: guard — if `isAdmin()` and only 1 super_admin left → abort(422); `$user->update(['is_active' => false])`, AuditLogger `user_deactivated`, redirect back with toast
    - `assignRole($user, Request)`: guard — if `$user->id === auth()->id()` → abort(422, 'Cannot modify your own role'); `$user->roles()->syncWithoutDetaching([$role->id])`, AuditLogger `role_assigned`
    - `removeRole($user, Request)`: guard — if last super_admin → abort(422, 'Cannot remove the last Super Admin'); `$user->roles()->detach($role->id)`, AuditLogger `role_removed`
    - _Requirements: R8, R9, R10, R20.1–R20.7, R24_

  - [ ] 5.3 Create `app/Http/Controllers/Admin/EventController.php`
    - `Gate::authorize('manage-events')` at top of every action
    - `index()`: paginate 20 all events (non-soft-deleted) across all organizers; search name/slug; filter by status and owner; each record: id, uuid, name, slug, status, owner name/email, photo count, created_at
    - `show($event)`: full detail per R12.5 using route model binding on uuid
    - `archive($event)`: set `status = 'archived'`, AuditLogger `event_archived`, redirect back with toast
    - `destroy($event)`: soft-delete via `$event->delete()`, AuditLogger `event_deleted` (metadata: `event_uuid`), redirect back with toast
    - `findOrFail()` / route model binding — 404 for missing/soft-deleted
    - _Requirements: R12, R13.3, R20_

  - [ ] 5.4 Create `app/Http/Controllers/Admin/PhotoController.php`
    - `Gate::authorize('manage-photos')` at top of every action
    - `index()`: paginate 20; filter by event (uuid/id), status, owner; each record per R14.3; NEVER include `original_path`, `optimized_path`, `thumbnail_path`
    - `show($photo)`: detail per R14.4; NEVER include storage paths (R14.5)
    - `destroy($photo)`: AuditLogger `photo_deleted` (metadata: `event_uuid`, `original_filename`) THEN `app(PhotoRemovalService::class)->remove($photo)`, redirect back with toast
    - Route model binding on uuid; 404 for missing/soft-deleted
    - _Requirements: R14, R20, R24.3_

  - [ ] 5.5 Create `app/Http/Controllers/Admin/PlanController.php`
    - `Gate::authorize('manage-plans')` at top of every action
    - `index()`: for each plan slug from `config('plans')`, call `Plan::fromEffectiveConfig($slug)`; also load `PlanOverride` row to determine source flags (`override` vs `config`) per field; return `AdminPlan[]` shape
    - `update($slug, Request)`: validate limit fields (nullable unsigned ints/bigint); `PlanOverride::updateOrCreate(['slug' => $slug], [...])` ; AuditLogger `plan_limit_updated` (metadata: old vs new values); redirect back with toast
    - `deactivate($slug)`: `PlanOverride::updateOrCreate(['slug' => $slug], ['is_active' => false])`; AuditLogger `plan_deactivated`; no cascade deletion
    - _Requirements: R15, R20_

  - [ ] 5.6 Create `app/Http/Controllers/Admin/SubscriptionController.php`
    - `Gate::authorize('manage-subscriptions')` at top of every action
    - `index()`: paginate 20; filter by status, plan slug, user (id/email); each record per R16.3; NEVER expose `provider_subscription_id`
    - `show($subscription)`: detail per R16.4; NEVER expose `provider_subscription_id`
    - Read-only — no create/update/cancel endpoints
    - _Requirements: R16, R20_

  - [ ] 5.7 Create `app/Http/Controllers/Admin/PaymentController.php`
    - `Gate::authorize('view-payments')` at top of every action
    - `index()`: paginate 20 sorted `created_at` desc; filter by status, date range (from/to), user (id/email); each record per R17.3; NEVER expose `provider_payment_id` or `metadata`
    - `show($payment)`: detail per R17.4; NEVER expose `provider_payment_id` or `metadata`
    - Read-only — no create/refund/update endpoints
    - _Requirements: R17, R20_

  - [ ] 5.8 Create `app/Http/Controllers/Admin/AuditLogController.php`
    - `Gate::authorize('view-audit-logs')` at top of every action
    - `index()`: paginate 25 sorted `created_at` desc; filter by action (exact), actor user id/email, date range, `target_type`; each record per R19.3; raw `metadata` excluded from list view
    - `show($log)`: all fields including `metadata` (sensitive keys stripped) and `user_agent`
    - No DELETE endpoint exists
    - _Requirements: R18, R19, R20_

- [ ] 6. Frontend — types, layout, app.tsx, and admin pages (Wave 5 — depend on Wave 4 routes being defined)

  - [ ] 6.1 Modify `resources/js/app.tsx` — add Admin layout resolver case
    - Before the default layout case, add: `if (name.startsWith('Admin/')) return AdminLayout;`
    - Import `AdminLayout` from `@/layouts/admin-layout`
    - _Requirements: R6.2, R22.2_

  - [ ] 6.2 Create `resources/js/layouts/admin-layout.tsx`
    - Sidebar navigation with 8 items: Dashboard (`/admin`), Users (`/admin/users`), Events (`/admin/events`), Photos (`/admin/photos`), Plans (`/admin/plans`), Subscriptions (`/admin/subscriptions`), Payments (`/admin/payments`), Audit Logs (`/admin/audit-logs`)
    - Active link highlighting using `useCurrentUrl()` / `isCurrentOrParentUrl()` (same pattern as settings layout)
    - No organizer navigation items
    - Mobile-friendly using Tailwind v4 flex/responsive utilities and existing shadcn/ui primitives
    - Mirrors `resources/js/layouts/settings/layout.tsx` structural pattern
    - _Requirements: R6.1–R6.8_

  - [ ] 6.3 Create `resources/js/types/admin.ts`
    - Export interfaces: `AdminUser`, `AdminEvent`, `AdminPhoto`, `AdminPlan`, `AdminSubscription`, `AdminPayment`, `AuditLogEntry`, `AdminDashboardStats`, `PaginationMeta`
    - `AdminUser` MUST NOT include: `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`
    - `AdminPhoto` MUST NOT include: `original_path`, `optimized_path`, `thumbnail_path`
    - `AdminSubscription` MUST NOT include: `provider_subscription_id`
    - `AdminPayment` MUST NOT include: `provider_payment_id`, `metadata`
    - Include source flag fields in `AdminPlan`: `price_source`, `max_active_events_source`, `max_photos_per_event_source`, `max_storage_bytes_source` (`'override' | 'config'`)
    - `AuditLogEntry.actor` is `{ id: number; name: string; email: string } | null` (null when `user_id` is NULL)
    - _Requirements: R8.6, R14.5, R16.6, R17.6, Design §types_

  - [ ] 6.4 Create `resources/js/pages/Admin/Dashboard.tsx`
    - Inertia page name: `Admin/Dashboard`
    - Props: `AdminDashboardStats`
    - Stat cards for users (total/active/inactive/admin), events (total/by-status), photos (total/by-status), subscriptions (free/pro/active/canceled), payments (succeeded/failed + recent 5)
    - Recent payments list: user name/email, amount, currency, status, date
    - Mobile-first using Phase 11 Card pattern (no raw HTML tables)
    - _Requirements: R7_

  - [ ] 6.5 Create `resources/js/pages/Admin/Users/Index.tsx`
    - Inertia page name: `Admin/Users/Index`
    - Props: `AdminUser[]`, `PaginationMeta`, search/filter state
    - Paginated card list (15/page); search input for name/email; filter selects for role and `is_active`
    - Each card: name, email, is_active badge, roles, plan, event count, created_at; link to show
    - _Requirements: R8.1–R8.4, R20_

  - [ ] 6.6 Create `resources/js/pages/Admin/Users/Show.tsx`
    - Inertia page name: `Admin/Users/Show`
    - Props: `AdminUser` (full detail)
    - Display: account info, role badges, is_active status, plan + subscription, event count, photo count, storage used, 2FA status
    - Activate button (if inactive); deactivate button (if active) — both trigger shadcn/ui `Dialog` confirmation before submitting
    - Role assign/remove with confirmation dialog; self-promo note if actor is viewing own profile
    - Inertia `useForm` POST to `admin.users.activate` / `admin.users.deactivate` / `admin.users.roles.assign` / `admin.users.roles.remove`
    - _Requirements: R8.5–R8.7, R9, R10, R21_

  - [ ] 6.7 Create `resources/js/pages/Admin/Events/Index.tsx`
    - Inertia page name: `Admin/Events/Index`
    - Props: `AdminEvent[]`, `PaginationMeta`, filters
    - Paginated card list (20/page); search by name/slug; filter by status
    - Each card: name, slug, status badge, owner name/email, photo count, created_at; link to show
    - _Requirements: R12.1–R12.4, R20_

  - [ ] 6.8 Create `resources/js/pages/Admin/Events/Show.tsx`
    - Inertia page name: `Admin/Events/Show`
    - Props: `AdminEvent` (full detail)
    - Display: all event fields, owner info, photo count
    - Archive button → `Dialog` confirmation → `admin.events.archive`
    - Delete button → `Dialog` confirmation (destructive, explains irreversibility) → `admin.events.destroy`
    - _Requirements: R12.5–R12.11, R21_

  - [ ] 6.9 Create `resources/js/pages/Admin/Photos/Index.tsx`
    - Inertia page name: `Admin/Photos/Index`
    - Props: `AdminPhoto[]`, `PaginationMeta`, filters
    - Paginated card list (20/page); filter by event, status; no raw storage paths in rendering
    - Each card: original_filename, mime_type, file_size, status badge, event name, owner; link to show
    - _Requirements: R14.1–R14.3, R20_

  - [ ] 6.10 Create `resources/js/pages/Admin/Photos/Show.tsx`
    - Inertia page name: `Admin/Photos/Show`
    - Props: `AdminPhoto` (full metadata, no paths)
    - Display: uuid, filename, mime_type, file_size, dimensions, status, event, owner, timestamps
    - Delete button → `Dialog` confirmation → `admin.photos.destroy`
    - MUST NOT render or reference `original_path`, `optimized_path`, or `thumbnail_path`
    - _Requirements: R14.4–R14.11, R21_

  - [ ] 6.11 Create `resources/js/pages/Admin/Plans/Index.tsx`
    - Inertia page name: `Admin/Plans/Index`
    - Props: `AdminPlan[]`
    - Display effective limits for each plan with source badges (`override` / `config`) per field
    - Inline edit form for limit fields per plan; submit → `admin.plans.update` via `useForm` PUT
    - Deactivate button → `Dialog` confirmation → `admin.plans.deactivate`
    - _Requirements: R15.3–R15.8_

  - [ ] 6.12 Create `resources/js/pages/Admin/Subscriptions/Index.tsx`
    - Inertia page name: `Admin/Subscriptions/Index`
    - Props: `AdminSubscription[]`, `PaginationMeta`, filters
    - Paginated card list (20/page); filter by status and plan; read-only (no mutation actions)
    - Each card: user name/email, plan, provider, status, period end, created_at; link to show (if detail page exists)
    - _Requirements: R16.1–R16.3, R20_

  - [ ] 6.13 Create `resources/js/pages/Admin/Payments/Index.tsx`
    - Inertia page name: `Admin/Payments/Index`
    - Props: `AdminPayment[]`, `PaginationMeta`, filters
    - Paginated card list (20/page); filter by status and date range; read-only; no secrets rendered
    - Each card: user name/email, amount, currency, status, provider, paid_at; link to show
    - _Requirements: R17.1–R17.3, R20_

  - [ ] 6.14 Create `resources/js/pages/Admin/AuditLogs/Index.tsx`
    - Inertia page name: `Admin/AuditLogs/Index`
    - Props: `AuditLogEntry[]`, `PaginationMeta`, filters
    - Paginated card list (25/page); filter by action, actor, date range, target_type; no DELETE UI
    - Each card: actor name/email (or "System"), action, target_type, target_id, description, ip_address, created_at
    - No raw `metadata` in list view; link to detail if needed
    - _Requirements: R18, R19, R20_

- [ ] 7. Factories and feature tests (Wave 6 — depend on Wave 0–5)

  - [ ] 7.1 Modify `database/factories/UserFactory.php` — add new states
    - Add `superAdmin()` state: `afterCreating` assigns `super_admin` role via `Role::firstOrCreate` + `$user->roles()->syncWithoutDetaching([$role->id])`
    - Add `inactive()` state: `$this->state(['is_active' => false])`
    - Existing default state unchanged (no `is_active` override → DB default `true`)
    - _Requirements: R26.1–R26.2_

  - [ ] 7.2 Create `database/factories/AuditLogFactory.php`
    - Defaults: `action = 'user_activated'`, `target_type = 'User'`, `target_id = null`, `description = 'User activated.'`, `metadata = []`, `ip_address = '127.0.0.1'`, `user_agent = 'PHPUnit'`, `created_at = now()`
    - `user_id` defaults to a freshly created `User`'s id (via `User::factory()` in the definition)
    - _Requirements: R26.4_

  - [ ] 7.3 Create `tests/Feature/Admin/AdminAccessTest.php`
    - P1/R5 — guest → redirect to login on `GET /admin`
    - P1 — organizer (non-admin) → 403 on `GET /admin` and representative sub-routes (`GET /admin/users`, `GET /admin/events`, etc.)
    - Super Admin active → 200 on `GET /admin` and all main sub-routes
    - P4/R11 — inactive Super Admin → 403 on `GET /admin`
    - P4/R11 — inactive organizer with forged session → 403 on organizer routes
    - _Requirements: R5, R9, R11, R25.1, Design P1, P4_

  - [ ] 7.4 Create `tests/Feature/Admin/UserManagementTest.php`
    - List returns paginated results; search by name and email works; filter by role and `is_active` works
    - Show returns full detail; does NOT include password/secrets (R8.6)
    - Activate succeeds: `is_active` set to true, audit log created
    - Deactivate succeeds: `is_active` set to false, audit log created
    - Deactivate last Super Admin → 422
    - _Requirements: R8, R9, R25.2, Design P3_

  - [ ] 7.5 Create `tests/Feature/Admin/RoleManagementTest.php`
    - `assignRole` succeeds: role pivot row added, audit log entry `role_assigned` created
    - `removeRole` succeeds: role pivot row removed, audit log entry `role_removed` created
    - P2 — self-promotion rejected: actor assigns `super_admin` to self → 422
    - P3 — last Super Admin remove rejected → 422
    - Organizer calling role endpoints → 403 (`EnsureSuperAdmin`)
    - _Requirements: R10, R25.2, Design P2, P3_

  - [ ] 7.6 Create `tests/Feature/Admin/EventManagementTest.php`
    - Admin list returns events from all organizers (not just actor's own)
    - Show returns event detail
    - Archive sets `status = 'archived'`, creates `event_archived` audit log
    - Soft-delete via destroy, creates `event_deleted` audit log
    - P5 — organizer A cannot access organizer B's events via organizer routes (isolation preserved)
    - _Requirements: R12, R13, R25.3, Design P5_

  - [ ] 7.7 Create `tests/Feature/Admin/PhotoManagementTest.php`
    - Admin list returns photos from all events
    - Show returns metadata without storage paths (P8)
    - P7 — destroy: `Storage::fake('public')`, create fake file on all 3 paths, call destroy, assert photo trashed and all 3 storage paths missing
    - Audit log entry `photo_deleted` created on destroy
    - Organizer cannot access admin photo routes → 403
    - _Requirements: R14, R25.4, Design P7, P8_

  - [ ] 7.8 Create `tests/Feature/Admin/PlanManagementTest.php`
    - View effective plans returns correct values from config
    - P6 — update: create `PlanOverride` with one field set; `Plan::fromEffectiveConfig` returns override for that field and config for others
    - Update creates `plan_limit_updated` audit log with changed field values
    - Deactivate plan: `plan_overrides.is_active` set to false, `plan_deactivated` audit log created
    - P10 — after limit update, existing events and photos for users on that plan are unchanged (no cascade)
    - Organizer cannot access plan routes → 403
    - _Requirements: R15, R25.5, Design P6, P10_

  - [ ] 7.9 Create `tests/Feature/Admin/SubscriptionPaymentTest.php`
    - Admin views all subscriptions (cross-organizer); response excludes `provider_subscription_id`
    - Organizer's subscription list only returns own subscriptions (isolation)
    - Admin views all payments (cross-organizer); response excludes `provider_payment_id` and `metadata`
    - Admin cannot POST/PUT/DELETE on subscriptions or payments (route not found / 404 / 405)
    - _Requirements: R16, R17, R25.6_

  - [ ] 7.10 Create `tests/Feature/Admin/AuditLogTest.php`
    - Each of the 9 audited actions (`user_activated`, `user_deactivated`, `role_assigned`, `role_removed`, `event_archived`, `event_deleted`, `photo_deleted`, `plan_limit_updated`, `plan_deactivated`) creates a correctly formed `audit_logs` record (actor id, action, target_type, target_id, description, ip_address)
    - Organizer cannot access `GET /admin/audit-logs` → 403
    - No DELETE route exists (assert `DELETE /admin/audit-logs/{id}` returns 404/405)
    - `user_id` SET NULL when admin user is deleted (DB constraint test)
    - _Requirements: R18, R19, R25.7_

  - [ ] 7.11 Create `tests/Feature/Admin/AdminSecurityTest.php`
    - IDOR — organizer A cannot reach organizer B's events/photos via `/admin/*` → 403 (EnsureSuperAdmin blocks)
    - Privilege escalation — `POST /admin/users/{id}/roles/assign` as organizer → 403
    - Self-promotion — Super Admin assigns `super_admin` to self → 422
    - Inactive user blocked at login (`POST /login`) → redirect back, not authenticated
    - Inactive organizer forged session → 403 on organizer route
    - P8 — for each admin endpoint, Inertia JSON response does NOT contain `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`, `original_path`, `optimized_path`, `thumbnail_path`, `provider_payment_id`, `secret_key`, `webhook_secret` at any nesting depth
    - P6 — `Plan::fromEffectiveConfig` null-coalesces correctly (all-null override → full config fallback, all-set override → all override values)
    - _Requirements: R24, R25.8, Design P1–P4, P6, P8, P9_

- [ ] 7.12 Final verification checkpoint
  - Run `php artisan test` — expect all 252 Phase 1–12 tests + new Phase 13 tests, 0 failures
  - Run `npx tsc --noEmit` — expect 0 TypeScript errors
  - Run `npm run build` — expect successful frontend build
  - Document local admin bootstrap: run `php artisan admin:create-admin`, provide email/name/password, verify login at `/admin`, verify organizer flow at `/events` still works
  - _Requirements: R25.9, R29.1, R29.6, R29.7_

- [ ] 8. Documentation (Wave 8)

  - [ ] 8.1 Create `docs/admin.md`
    - Sections: role system (roles table, user_roles pivot, how to add new roles), user status mechanism (is_active, login blocking, organizer route enforcement), `admin:create-admin` usage with step-by-step example, admin route structure (routes/admin.php, middleware stack `['auth','verified','admin']`), authorization architecture (Gate::before, named Gates, EventPolicy::before), AuditLogger service (all 9 audited action strings, metadata format examples, what metadata must never contain), local dev setup instructions
    - Include at least one example of: a Gate check call, an AuditLogger call, a `hasRole()` usage, a `fromEffectiveConfig()` usage
    - Accurate to the implemented code — no placeholders or aspirational descriptions
    - Written in Markdown; never documents real credentials or secret values
    - _Requirements: R28.1–R28.3_

---

## Notes

- Wave 0 tasks (1.1–1.6) are all independent; run migrations in the order their timestamps dictate: `add_is_active`, `create_roles`, `create_user_roles`, `create_plan_overrides`, `create_audit_logs`. The roles seeder (1.6) must run after migrations so the `roles` table exists.
- Wave 1 tasks (2.1–2.6) depend on the schema being in place. `Role` model (2.1) and `PlanOverride` model (2.2) are independent of each other and can be built in parallel.
- Wave 2 tasks (3.1–3.8) depend on the models. `AuditLogger` (3.1) depends on `AuditLog` model (2.3). `PhotoRemovalService` (3.2) depends on `Photo` model (existing). Middleware (3.3, 3.4) depends on `User` helpers (2.4). Service providers (3.5, 3.6) depend on Gates being registrable after models exist.
- Wave 3 tasks (4.1–4.4) depend on all middleware and services. Task 4.1 must come before 4.2 (require statement). Task 4.3 (aliases) must come before routes are used.
- Wave 4 controllers (5.1–5.8) depend on routes, services, and models all being in place. Controllers can be built in any order within this wave.
- Wave 5 frontend tasks (6.1–6.14) depend on backend routes being defined (for `route()` helpers and Inertia page names). Tasks 6.1 (app.tsx), 6.2 (AdminLayout), and 6.3 (types) are foundational for all page components. Pages (6.4–6.14) can be built in any order within this wave.
- Wave 6 tests (7.1–7.12) depend on all implementation. Factories (7.1, 7.2) must come before test files. `php artisan test` in task 7.12 validates the full regression gate.
- Wave 8 documentation (8.1) is the final deliverable and can only be accurate after implementation is complete.
- Tasks marked with `/admin/*` routes use route model binding: `{user}` binds `User`, `{event:uuid}` binds `Event` by uuid, `{photo:uuid}` binds `Photo` by uuid.
- All `AuditLogger` calls place the log call BEFORE the destructive action (for photos) so the log is written even if file deletion partially fails, or AFTER successful state change (for activate/deactivate) following the pattern used in other Phase controllers.
- `is_active` is intentionally excluded from `User::$fillable` to prevent mass-assignment from request data; set exclusively via `User::where(...)->update(['is_active' => ...])` or `$user->update(['is_active' => ...])` in admin controllers.

---

## Task Dependency Graph

```json
{
  "waves": [
    {
      "id": 0,
      "tasks": ["1.1", "1.2", "1.3", "1.4", "1.5", "1.6"]
    },
    {
      "id": 1,
      "tasks": ["2.1", "2.2", "2.3", "2.4", "2.5", "2.6"]
    },
    {
      "id": 2,
      "tasks": ["3.1", "3.2", "3.3", "3.4", "3.5", "3.6", "3.7", "3.8"]
    },
    {
      "id": 3,
      "tasks": ["4.1", "4.2", "4.3", "4.4"]
    },
    {
      "id": 4,
      "tasks": ["5.1", "5.2", "5.3", "5.4", "5.5", "5.6", "5.7", "5.8"]
    },
    {
      "id": 5,
      "tasks": ["6.1", "6.2", "6.3", "6.4", "6.5", "6.6", "6.7", "6.8", "6.9", "6.10", "6.11", "6.12", "6.13", "6.14"]
    },
    {
      "id": 6,
      "tasks": ["7.1", "7.2", "7.3", "7.4", "7.5", "7.6", "7.7", "7.8", "7.9", "7.10", "7.11"]
    },
    {
      "id": 7,
      "tasks": ["7.12"]
    },
    {
      "id": 8,
      "tasks": ["8.1"]
    }
  ]
}
```
