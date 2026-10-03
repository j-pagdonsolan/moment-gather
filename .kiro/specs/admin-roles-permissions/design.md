# Design Document — Admin Roles, Permissions & Administration (Phase 13)

## Overview

Phase 13 adds a secure, fully self-contained administrative system on top of MomentGather's completed Phases 1–12 (252 passing tests). The design is strictly additive: no existing organizer route, controller, model, or test is removed or replaced.

The system introduces:
- **Role-based access control** via a `roles` / `user_roles` many-to-many structure (Decision A).
- **User status** via an `is_active` boolean on `users` (Decision B), with Fortify-pipeline enforcement on login and middleware enforcement on organizer routes.
- **A dedicated admin area** (`/admin/*`) protected by a single `EnsureSuperAdmin` middleware, with its own React layout, sidebar navigation, and Inertia page tree.
- **Admin management capabilities** for users, events, photos, plans, subscriptions, payments, and an append-only audit log.
- **Plan override table** so admins can adjust plan limits at runtime without a code deploy.
- **`admin:create-admin` Artisan command** to bootstrap the first Super Admin.

Out of scope: Redis, Horizon, cloud storage, S3, AI, real payment modification, GDPR export, email notifications for admin actions.

---

## Architecture

### Layered overview

```
┌────────────────────────────────────────────────────────────────┐
│  Browser (React 19 + Inertia v3 + TypeScript + Tailwind v4)   │
│  AdminLayout  ←  app.tsx resolves Admin/* pages here          │
│  Admin/* pages  (Dashboard, Users, Events, Photos, Plans …)   │
└──────────────────────────┬─────────────────────────────────────┘
                           │  Inertia XHR / full-page
┌──────────────────────────▼─────────────────────────────────────┐
│  HTTP layer                                                     │
│  routes/admin.php  (require'd at bottom of routes/web.php)    │
│  middleware stack: ['auth', 'verified', 'admin']               │
│    auth       → redirect to /login if guest                    │
│    verified   → redirect to email-verify if unverified         │
│    admin      → EnsureSuperAdmin (403 if not super_admin       │
│                  or is_active=false)                            │
│  Admin\* Controllers  (app/Http/Controllers/Admin/)            │
└──────────────────────────┬─────────────────────────────────────┘
                           │
┌──────────────────────────▼─────────────────────────────────────┐
│  Domain / Authorization layer                                  │
│  Gate::before()  → super admins pass every policy check       │
│  Named Gates: manage-users, manage-events, manage-photos,     │
│    manage-plans, manage-subscriptions, view-payments,         │
│    view-audit-logs                                             │
│  EventPolicy::before()  → true for isAdmin(), else owner==    │
│  AuditLogger service  → appends to audit_logs                 │
│  PhotoRemovalService  → soft-delete + Storage cleanup         │
│  Plan::fromEffectiveConfig()  → merges plan_overrides + config │
│  BillingService::currentPlan()  → uses fromEffectiveConfig()  │
└──────────────────────────┬─────────────────────────────────────┘
                           │
┌──────────────────────────▼─────────────────────────────────────┐
│  Database (MySQL / :memory: SQLite in tests)                  │
│  New tables: roles, user_roles, plan_overrides, audit_logs    │
│  Modified: users (+ is_active)                                │
│  Unchanged: events, photos, subscriptions, payments, …        │
└────────────────────────────────────────────────────────────────┘
```

### Key protection layers (request flow)

Every admin request passes through three sequential layers:

1. **Route middleware** (`EnsureSuperAdmin`): the first gate. Checks `$user->isAdmin() && $user->isActive()` before any controller code runs. Returns 403 immediately on failure. Handles R5, R22.5.

2. **Gate / Policy** (central authorization): `Gate::before()` is registered in `AppServiceProvider::boot()` so super admins receive `true` from every policy check without touching individual policies. Named gates (`manage-users`, etc.) are also registered here. Controllers call `Gate::authorize('manage-users')` — no scattered `if` checks. Handles R4.

3. **Business-rule guards** (in controllers / services): last-admin protection (R9.3, R10.4), self-promotion check (R10.3), secret non-exposure (R24.3). These are the final layer before the action executes.

### Organizer route `is_active` enforcement (R11)

The `EnsureActiveUser` middleware is applied to the existing `['auth', 'verified']` organizer group. It checks `$request->user()?->isActive()` and aborts with 403 when false. This is one middleware, applied once at the group level — no controller changes.

---

## Components and Interfaces

### New database migrations (in dependency order)

| # | Migration name | Purpose | Requirement |
|---|----------------|---------|-------------|
| 1 | `add_is_active_to_users_table` | Adds `is_active boolean default true` to `users` | R2 |
| 2 | `create_roles_table` | `id, name (unique), display_name, timestamps` | R1 |
| 3 | `create_user_roles_table` | `user_id FK, role_id FK, composite PK` | R1 |
| 4 | `create_plan_overrides_table` | `id, slug (unique), nullable limit fields, is_active` | R15 |
| 5 | `create_audit_logs_table` | `id, user_id nullable FK (SET NULL), action, target_type, target_id, description, metadata json, ip_address, user_agent, created_at only` | R18 |

Migration 2 must run before 3 (foreign key). Migration 1 can run independently.

### New models

#### `app/Models/Role.php`
```php
// Relationships
public function users(): BelongsToMany  // via user_roles pivot
// Key fields: id, name (slug), display_name, timestamps
// Validation: unique index on name
```
Requirement: R1.1–R1.3, R27.1

#### `app/Models/PlanOverride.php`
```php
// Key fields: id, slug (unique), max_active_events (nullable int),
//   max_photos_per_event (nullable int), max_storage_bytes (nullable bigint),
//   price (nullable int, minor units), is_active (bool default true),
//   created_at, updated_at
// No FK to any other table (plan slugs are config strings)
```
Requirement: R15.1, R27.4

#### `app/Models/AuditLog.php`
```php
// $timestamps = false
// protected $dates = ['created_at']  (manually set on create)
// No updated_at column (append-only design)
// Key fields: id, user_id (nullable FK → users.id SET NULL),
//   action (varchar), target_type (nullable varchar),
//   target_id (nullable unsignedBigInt), description (text),
//   metadata (nullable json), ip_address (nullable varchar),
//   user_agent (nullable varchar), created_at (timestamp)
// No update() calls anywhere — insert only
```
Requirement: R18.1, R18.4, R27.5

### Modified models

#### `app/Models/User.php` — additions only
```php
// New relationship
public function roles(): BelongsToMany  // via user_roles pivot, R1.4

// New helpers
public function hasRole(string $slug): bool  // R1.5
public function isAdmin(): bool              // $this->hasRole('super_admin'), R1.6
public function isActive(): bool             // (bool) $this->is_active, R2.2

// Modified casts() — add 'is_active' => 'boolean'
// is_active is NOT added to #[Fillable]; set only via explicit DB calls in admin
```
The `#[Fillable]` attribute stays `['name', 'email', 'password']`. `is_active` is set through `User::where(...)->update(['is_active' => false])` in admin controllers, preventing mass-assignment from client requests.

Requirement: R1.4–R1.6, R2.2, R24

### New services

#### `app/Services/AuditLogger.php`
```php
class AuditLogger
{
    public function log(
        User|null $actor,
        string $action,
        string $targetType,
        int|null $targetId,
        string $description,
        array $metadata = [],
    ): AuditLog
    // Reads ip_address and user_agent from request()
    // metadata must NOT contain passwords, secrets, or paths
}
```
Audited action strings: `user_activated`, `user_deactivated`, `role_assigned`, `role_removed`, `event_archived`, `event_deleted`, `photo_deleted`, `plan_limit_updated`, `plan_deactivated`.

Requirement: R18.2–R18.3, R18.5

#### `app/Services/PhotoRemovalService.php`
```php
class PhotoRemovalService
{
    public function remove(Photo $photo): void
    // 1. Soft-deletes the Photo record ($photo->delete())
    // 2. Deletes original_path, optimized_path, thumbnail_path
    //    via Storage::disk('public')->delete([...]) if paths are non-null
    // Pattern mirrors ProcessPhoto::failed() — same disk, same delete call
}
```
The three paths deleted: `$photo->original_path`, `$photo->optimized_path`, `$photo->thumbnail_path`.

Requirement: R14.6, R25.4

### Modified existing services

#### `app/Billing/Plan.php` — new static method
```php
public static function fromEffectiveConfig(string $slug): self
// 1. Loads base config: $data = config('plans')[$slug] ?? config('plans')['free']
// 2. Queries PlanOverride::where('slug', $slug)->first()
// 3. For each of: max_active_events, max_photos_per_event, max_storage_bytes, price
//    if override row exists AND field is non-null → use override value
//    else → use config value
// 4. Constructs and returns Plan with merged values
// fromConfig() is unchanged (backward compatible)
```
Requirement: R15.2, R29.3

#### `app/Billing/BillingService.php` — modify `currentPlan()`
```php
// Before: Plan::fromConfig('pro') / Plan::fromConfig('free')
// After:  Plan::fromEffectiveConfig('pro') / Plan::fromEffectiveConfig('free')
// All other methods unchanged; existing tests continue to pass because
// fromEffectiveConfig falls back to config when plan_overrides is empty
```
Requirement: R15.2, R15.9, R29.3

### Modified middleware

#### `app/Http/Middleware/HandleInertiaRequests.php` — extend `share()`
```php
// Add to the 'auth' sub-array:
'auth' => [
    'user' => $request->user(),
    // NEW:
    'isAdmin' => $request->user()?->isAdmin() ?? false,
    'roles'   => $request->user()?->roles->pluck('name')->toArray() ?? [],
],
// NEVER include billing secrets here
```
Requirement: R22.1, R6.2 (frontend layout resolver reads auth.user.roles)

### New middleware

#### `app/Http/Middleware/EnsureSuperAdmin.php`
```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if (! $user?->isAdmin() || ! $user->isActive()) {
        abort(403);
    }
    return $next($request);
}
```
Registered as alias `'admin'` in `bootstrap/app.php`:
```php
$middleware->alias(['admin' => EnsureSuperAdmin::class]);
```
Requirement: R5.1–R5.7

#### `app/Http/Middleware/EnsureActiveUser.php`
```php
public function handle(Request $request, Closure $next): Response
{
    if ($request->user() && ! $request->user()->isActive()) {
        abort(403);
    }
    return $next($request);
}
```
Applied to the organizer `['auth', 'verified']` group in `routes/web.php`.
Requirement: R11.1–R11.2

### Modified authorization

#### `app/Providers/AppServiceProvider.php` — additions in `boot()`
```php
// Gate::before — grants all policy checks to super admins globally
Gate::before(function (User $user, string $ability): ?bool {
    return $user->isAdmin() ? true : null;
});

// Named admin gates
Gate::define('manage-users',         fn (User $u) => $u->isAdmin());
Gate::define('manage-events',        fn (User $u) => $u->isAdmin());
Gate::define('manage-photos',        fn (User $u) => $u->isAdmin());
Gate::define('manage-plans',         fn (User $u) => $u->isAdmin());
Gate::define('manage-subscriptions', fn (User $u) => $u->isAdmin());
Gate::define('view-payments',        fn (User $u) => $u->isAdmin());
Gate::define('view-audit-logs',      fn (User $u) => $u->isAdmin());
```
Requirement: R4.1, R4.3–R4.4

#### `app/Policies/EventPolicy.php` — add `before()` method
```php
public function before(User $user, string $ability): ?bool
{
    return $user->isAdmin() ? true : null;
}
// Existing view/update/delete methods unchanged (R13.1)
```
Requirement: R4.2, R13.2

### New Artisan command

#### `app/Console/Commands/CreateAdminUser.php`
- Signature: `admin:create-admin`
- Namespace: `App\Console\Commands` (follows `ProcessPhotos.php` convention)
- Flow:
  1. Prompt email (validated with `filter_var(..., FILTER_VALIDATE_EMAIL)`)
  2. Prompt name
  3. Prompt password (hidden input via `secret()`)
  4. Prompt password confirmation (hidden input via `secret()`)
  5. If email exists → assign `super_admin` role via pivot; display confirmation
  6. If email does not exist and passwords match → create `User`, set `is_active = true`, assign `super_admin` role; display confirmation
  7. If email does not exist and passwords mismatch → display error, exit 1, no records created
  8. If email invalid → display error, exit 1, no records created
  9. **Never** prints the plaintext password
  10. CLI-only; no HTTP endpoint

Requirement: R3.1–R3.9

### Modified bootstrap

#### `bootstrap/app.php` — add middleware alias
```php
->withMiddleware(function (Middleware $middleware): void {
    // ... existing config unchanged ...
    $middleware->alias(['admin' => \App\Http\Middleware\EnsureSuperAdmin::class]);
})
```
Requirement: R5.5

### New routes

#### `routes/admin.php` — loaded via `require __DIR__.'/admin.php';` at bottom of `routes/web.php`

```php
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
    Route::post('users/{user}/activate', [Admin\UserController::class, 'activate'])->name('users.activate');
    Route::post('users/{user}/deactivate', [Admin\UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/roles/assign', [Admin\UserController::class, 'assignRole'])->name('users.roles.assign');
    Route::post('users/{user}/roles/remove', [Admin\UserController::class, 'removeRole'])->name('users.roles.remove');

    // Events
    Route::get('events', [Admin\EventController::class, 'index'])->name('events.index');
    Route::get('events/{event:uuid}', [Admin\EventController::class, 'show'])->name('events.show');
    Route::post('events/{event:uuid}/archive', [Admin\EventController::class, 'archive'])->name('events.archive');
    Route::delete('events/{event:uuid}', [Admin\EventController::class, 'destroy'])->name('events.destroy');

    // Photos
    Route::get('photos', [Admin\PhotoController::class, 'index'])->name('photos.index');
    Route::get('photos/{photo:uuid}', [Admin\PhotoController::class, 'show'])->name('photos.show');
    Route::delete('photos/{photo:uuid}', [Admin\PhotoController::class, 'destroy'])->name('photos.destroy');

    // Plans
    Route::get('plans', [Admin\PlanController::class, 'index'])->name('plans.index');
    Route::put('plans/{slug}', [Admin\PlanController::class, 'update'])->name('plans.update');
    Route::post('plans/{slug}/deactivate', [Admin\PlanController::class, 'deactivate'])->name('plans.deactivate');

    // Subscriptions (read-only)
    Route::get('subscriptions', [Admin\SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/{subscription}', [Admin\SubscriptionController::class, 'show'])->name('subscriptions.show');

    // Payments (read-only)
    Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [Admin\PaymentController::class, 'show'])->name('payments.show');

    // Audit logs (read-only)
    Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{log}', [Admin\AuditLogController::class, 'show'])->name('audit-logs.show');
});
```

No DELETE route exists for audit-logs. Requirement: R5.5, R18.4

### New controllers — `app/Http/Controllers/Admin/`

All controllers:
- Call `Gate::authorize('manage-*')` or `Gate::authorize('view-*')` at the top of each action.
- Use `findOrFail()` / route model binding for 404 on missing records.
- Return `Inertia::render('Admin/PageName', $props)`.
- Use `->with/withCount/load` to eliminate N+1 queries.
- Paginate with `->paginate(n)` (not `->get()`).

| Controller | Key actions | Pagination | Requirement |
|------------|-------------|-----------|-------------|
| `DashboardController` | `index()` — aggregate stats via COUNT/GROUP BY | N/A | R7 |
| `UserController` | `index()`, `show()`, `activate()`, `deactivate()`, `assignRole()`, `removeRole()` | 15/page | R8–R10 |
| `EventController` | `index()`, `show()`, `archive()`, `destroy()` | 20/page | R12–R13 |
| `PhotoController` | `index()`, `show()`, `destroy()` | 20/page | R14 |
| `PlanController` | `index()`, `update()`, `deactivate()` | N/A | R15 |
| `SubscriptionController` | `index()`, `show()` (read-only) | 20/page | R16 |
| `PaymentController` | `index()`, `show()` (read-only) | 20/page | R17 |
| `AuditLogController` | `index()`, `show()` (read-only) | 25/page | R18–R19 |

**Response fields explicitly EXCLUDED** from all admin responses (R24.3):
- `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`
- `provider_payment_id`, `provider_subscription_id`, `metadata` (payments)
- `original_path`, `optimized_path`, `thumbnail_path` (photos)
- `config('billing.secret_key')`, `config('billing.webhook_secret')`

### `UserController` business-rule enforcement

```
activate($user):
  Gate::authorize('manage-users')
  $user->update(['is_active' => true])
  AuditLogger::log($admin, 'user_activated', 'User', $user->id, ...)
  Redirect back with flash toast

deactivate($user):
  Gate::authorize('manage-users')
  Guard: if $user->isAdmin() && User::whereHas('roles', name=super_admin)->count() <= 1 → 422
  $user->update(['is_active' => false])
  AuditLogger::log($admin, 'user_deactivated', ...)
  Redirect back with flash toast

assignRole($user, $request):
  Gate::authorize('manage-users')
  Guard: if $user->id === $request->user()->id → 422 "Cannot modify your own role"
  $role = Role::where('name', $request->role)->firstOrFail()
  $user->roles()->syncWithoutDetaching([$role->id])
  AuditLogger::log(...)
  Redirect back with toast

removeRole($user, $request):
  Gate::authorize('manage-users')
  Guard: if last super_admin → 422 "Cannot remove the last Super Admin"
  $user->roles()->detach($role->id)
  AuditLogger::log(...)
  Redirect back with toast
```

### `PhotoController::destroy()` logic

```
destroy($photo):
  Gate::authorize('manage-photos')
  AuditLogger::log($admin, 'photo_deleted', 'Photo', $photo->id, ..., ['event_uuid' => $photo->event->uuid])
  app(PhotoRemovalService::class)->remove($photo)
  Redirect back with toast
```

### Fortify authentication pipeline — `is_active` check (R2.4)

In `app/Providers/FortifyServiceProvider.php`, add an `authenticateUsing` callback:

```php
Fortify::authenticateUsing(function (Request $request) {
    $user = User::where('email', $request->email)->first();
    if (! $user || ! Hash::check($request->password, $user->password)) {
        return null;  // wrong credentials
    }
    if (! $user->isActive()) {
        return null;  // inactive — treated same as wrong credentials
    }
    return $user;
});
```

This pipeline runs before 2FA continuation; inactive users are blocked at the password check step.

### Frontend: `resources/js/layouts/admin-layout.tsx`

```tsx
// Pattern: mirrors settings/layout.tsx sidebar nav structure
// Uses: AppShell (variant="sidebar"), AppSidebar-style nav, useCurrentUrl for active highlights
// Nav items:
const adminNavItems = [
  { title: 'Dashboard',     href: '/admin' },
  { title: 'Users',         href: '/admin/users' },
  { title: 'Events',        href: '/admin/events' },
  { title: 'Photos',        href: '/admin/photos' },
  { title: 'Plans',         href: '/admin/plans' },
  { title: 'Subscriptions', href: '/admin/subscriptions' },
  { title: 'Payments',      href: '/admin/payments' },
  { title: 'Audit Logs',    href: '/admin/audit-logs' },
];
// No organizer nav items
// isCurrentOrParentUrl() for active link highlighting
// Mobile-friendly with Tailwind v4 flex/responsive utilities
```
Requirement: R6.1–R6.8

### Frontend: `resources/js/app.tsx` — layout resolver change

```tsx
// ADD before the default case:
case name.startsWith('Admin/'):
    return AdminLayout;
```
Requirement: R6.2, R22.2

### Frontend: `resources/js/types/admin.ts` (new file)

```ts
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  is_active: boolean;
  roles: string[];          // array of role slugs
  plan: 'free' | 'pro';
  event_count: number;
  photo_count?: number;     // detail view only
  storage_used_bytes?: number; // detail view only
  two_fa_enabled?: boolean; // detail view only
  created_at: string;
  updated_at: string;
  // NEVER includes: password, two_factor_secret, remember_token
}

export interface AdminEvent {
  id: number;
  uuid: string;
  name: string;
  slug: string;
  description: string | null;
  event_date: string | null;
  location: string | null;
  status: 'active' | 'draft' | 'archived';
  upload_enabled: boolean;
  photo_count: number;
  owner: { id: number; name: string; email: string };
  created_at: string;
  updated_at: string;
}

export interface AdminPhoto {
  id: number;
  uuid: string;
  original_filename: string;
  mime_type: string;
  file_size: number;
  width: number | null;
  height: number | null;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  event: { id: number; uuid: string; name: string };
  owner: { id: number; name: string; email: string };
  created_at: string;
  updated_at: string;
  // NEVER includes: original_path, optimized_path, thumbnail_path
}

export interface AdminPlan {
  slug: string;
  name: string;
  price: number;                    // effective (override or config)
  max_active_events: number;        // effective
  max_photos_per_event: number;     // effective
  max_storage_bytes: number;        // effective
  price_source: 'override' | 'config';
  max_active_events_source: 'override' | 'config';
  max_photos_per_event_source: 'override' | 'config';
  max_storage_bytes_source: 'override' | 'config';
  is_active: boolean;
}

export interface AdminSubscription {
  id: number;
  user: { id: number; name: string; email: string };
  plan: string;
  provider: string;
  status: string;
  current_period_start: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  canceled_at: string | null;
  created_at: string;
  updated_at: string;
  // NEVER includes: provider_subscription_id
}

export interface AdminPayment {
  id: number;
  user: { id: number; name: string; email: string };
  amount: number;
  currency: string;
  status: string;
  provider: string;
  paid_at: string | null;
  subscription_id: number | null;
  subscription_plan?: string;
  created_at: string;
  // NEVER includes: provider_payment_id, metadata
}

export interface AuditLogEntry {
  id: number;
  actor: { id: number; name: string; email: string } | null; // null when user_id is NULL
  action: string;
  target_type: string | null;
  target_id: number | null;
  description: string;
  ip_address: string | null;
  user_agent?: string;  // detail view only
  metadata?: Record<string, unknown>; // detail view only, sensitive fields stripped
  created_at: string;
}

export interface AdminDashboardStats {
  users: {
    total: number;
    active: number;
    inactive: number;
    super_admin_count: number;
  };
  events: {
    total: number;
    active: number;
    draft: number;
    archived: number;
  };
  photos: {
    total: number;
    by_status: { pending: number; processing: number; ready: number; failed: number };
  };
  subscriptions: {
    free_plan_users: number;
    pro_plan_users: number;
    active: number;
    canceled: number;
  };
  payments: {
    succeeded: number;
    failed_count: number;
    recent: AdminPayment[];  // 5 most recent
  };
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
```
Requirement: R7–R19, R8.6, R14.5, R16.6, R17.6

### Frontend: Admin pages (all in `resources/js/pages/Admin/`)

| File | Inertia name | Props | Requirement |
|------|-------------|-------|-------------|
| `Dashboard.tsx` | `Admin/Dashboard` | `AdminDashboardStats` | R7 |
| `Users/Index.tsx` | `Admin/Users/Index` | `AdminUser[], PaginationMeta, filters` | R8 |
| `Users/Show.tsx` | `Admin/Users/Show` | `AdminUser` | R8–R10 |
| `Events/Index.tsx` | `Admin/Events/Index` | `AdminEvent[], PaginationMeta, filters` | R12 |
| `Events/Show.tsx` | `Admin/Events/Show` | `AdminEvent` | R12 |
| `Photos/Index.tsx` | `Admin/Photos/Index` | `AdminPhoto[], PaginationMeta, filters` | R14 |
| `Photos/Show.tsx` | `Admin/Photos/Show` | `AdminPhoto` | R14 |
| `Plans/Index.tsx` | `Admin/Plans/Index` | `AdminPlan[]` | R15 |
| `Subscriptions/Index.tsx` | `Admin/Subscriptions/Index` | `AdminSubscription[], PaginationMeta, filters` | R16 |
| `Payments/Index.tsx` | `Admin/Payments/Index` | `AdminPayment[], PaginationMeta, filters` | R17 |
| `AuditLogs/Index.tsx` | `Admin/AuditLogs/Index` | `AuditLogEntry[], PaginationMeta, filters` | R18–R19 |

UI patterns: Card-based responsive list (Phase 11 pattern) for all list views — no raw HTML `<table>`. Confirmation dialogs use the existing shadcn/ui `Dialog` component (R21.3). Toast via `Inertia::flash('toast', {type, message})` → `useFlashToast` → Sonner (R23.2).

### Updated test factories

#### `database/factories/UserFactory.php` — new states
```php
public function superAdmin(): static
{
    return $this->afterCreating(function (User $user) {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);
        $user->roles()->syncWithoutDetaching([$role->id]);
    });
}

public function inactive(): static
{
    return $this->state(['is_active' => false]);
}
```
Requirement: R26.1–R26.2

#### `database/factories/AuditLogFactory.php` (new)
```php
// Defaults: action='user_activated', target_type='User', target_id=null,
//   description='...', metadata=[], ip_address='127.0.0.1', user_agent='PHPUnit'
// user_id defaults to a freshly created User's id
```
Requirement: R26.4

### Seeder

#### `database/seeders/RolesSeeder.php` (new) / added to `DatabaseSeeder`
```php
Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);
Role::firstOrCreate(['name' => 'organizer'],   ['display_name' => 'Organizer']);
```
Seeder is also called from the migration itself (or DatabaseSeeder) to ensure roles exist before any `user_roles` rows. Requirement: R1.3, R27.6

### New documentation

#### `docs/admin.md` (new)
Documents: role system, is_active mechanism, `admin:create-admin` usage, route structure, authorization architecture, AuditLogger, local dev setup. Requirement: R28.

---

## Data Models

### `users` table — added column

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `is_active` | `boolean` | `true` | Cast to `bool` on User model |

No other columns added to `users`. Role information is in `user_roles`.

### `roles` table

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PK auto-increment |
| `name` | `varchar(50)` | unique index |
| `display_name` | `varchar(100)` | — |
| `created_at` | `timestamp` | — |
| `updated_at` | `timestamp` | — |

Seeded records: `('super_admin', 'Super Admin')`, `('organizer', 'Organizer')`.

### `user_roles` table

| Column | Type | Constraints |
|--------|------|-------------|
| `user_id` | `bigint unsigned` | FK → users.id CASCADE DELETE; index |
| `role_id` | `bigint unsigned` | FK → roles.id CASCADE DELETE |

Composite PK: `(user_id, role_id)`. Additional index on `user_id` for fast `hasRole()` lookups.

### `plan_overrides` table

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PK |
| `slug` | `varchar(50)` | unique index |
| `max_active_events` | `int unsigned` | nullable |
| `max_photos_per_event` | `int unsigned` | nullable |
| `max_storage_bytes` | `bigint unsigned` | nullable |
| `price` | `int unsigned` | nullable (minor currency units) |
| `is_active` | `boolean` | default `true` |
| `created_at` | `timestamp` | — |
| `updated_at` | `timestamp` | — |

No FK to other tables. Slugs are config strings (`free`, `pro`).

**`Plan::fromEffectiveConfig($slug)` merge logic:**
```
base = config('plans')[$slug] ?? config('plans')['free']
override = PlanOverride::where('slug', $slug)->first()
for each field in [max_active_events, max_photos_per_event, max_storage_bytes, price]:
    effective[field] = (override && override->field !== null) ? override->field : base[field]
```
This is a pure field-by-field null-coalesce. Changing one field in overrides does not affect others.

### `audit_logs` table

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PK |
| `user_id` | `bigint unsigned` | nullable FK → users.id SET NULL on delete |
| `action` | `varchar(100)` | index on `action` |
| `target_type` | `varchar(100)` | nullable |
| `target_id` | `bigint unsigned` | nullable |
| `description` | `text` | — |
| `metadata` | `json` | nullable |
| `ip_address` | `varchar(45)` | nullable (supports IPv6) |
| `user_agent` | `text` | nullable |
| `created_at` | `timestamp` | index on `created_at` |

**No `updated_at` column.** The model sets `$timestamps = false` and manually sets `created_at` on insert. No UPDATE queries are ever issued on this table from application code. No DELETE endpoint exists in `routes/admin.php`.

**What audit metadata may contain (examples):**
```json
// plan_limit_updated
{ "changed": { "max_active_events": { "from": 1, "to": 5 } } }

// role_assigned
{ "role_slug": "super_admin" }

// photo_deleted
{ "event_uuid": "abc-123", "original_filename": "photo.jpg" }
```

**What audit metadata must NEVER contain:** passwords, `secret_key`, `webhook_secret`, `two_factor_secret`, `remember_token`, raw storage paths.

### Sensitive field policy for admin responses

| Field | Excluded from all admin responses |
|-------|----------------------------------|
| `users.password` | ✓ (User model #[Hidden]) |
| `users.two_factor_secret` | ✓ (User model #[Hidden]) |
| `users.two_factor_recovery_codes` | ✓ (User model #[Hidden]) |
| `users.remember_token` | ✓ (User model #[Hidden]) |
| `photos.original_path` | ✓ (never serialized in admin resource) |
| `photos.optimized_path` | ✓ |
| `photos.thumbnail_path` | ✓ |
| `payments.provider_payment_id` | ✓ |
| `payments.metadata` | ✓ |
| `subscriptions.provider_subscription_id` | ✓ |
| `config('billing.secret_key')` | ✓ (never in Inertia share or admin props) |
| `config('billing.webhook_secret')` | ✓ |

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Role isolation

*For any* authenticated user without the `super_admin` role, a request to any `/admin/*` route shall result in HTTP 403, regardless of the specific endpoint, HTTP method, or request payload.

**Validates: Requirements 5.3, 24.7**

---

### Property 2: Self-promotion rejection

*For any* authenticated Super Admin user, a POST request to `/admin/users/{self}/roles/assign` with `role=super_admin` (where `{self}` is the actor's own user ID) shall be rejected with HTTP 422.

**Validates: Requirements 10.3, 24.4**

---

### Property 3: Last-admin protection

*For any* system state where exactly one user holds the `super_admin` role, both a `POST /admin/users/{user}/deactivate` request and a `POST /admin/users/{user}/roles/remove` request targeting that user shall be rejected with HTTP 422. After the rejection, the system state (super admin count) shall be unchanged.

**Validates: Requirements 9.3, 10.4, 24.5**

---

### Property 4: Inactive user access enforcement

*For any* user with `is_active = false`: (a) a Fortify login attempt shall be rejected as if the credentials were wrong; (b) a request to any organizer route (under `['auth', 'verified']`) using a manually forged session for that user shall return HTTP 403.

**Validates: Requirements 2.4, 11.1**

---

### Property 5: Event ownership isolation preserved for organizers

*For any* two distinct organizer users (non-super-admin), a request by user A to view, edit, or delete an event owned by user B via organizer routes shall return HTTP 403 — regardless of any Phase 13 additions.

**Validates: Requirements 13.1, 13.2, 29.2**

---

### Property 6: Plan override correctness

*For any* plan slug and any `PlanOverride` row where a subset of nullable fields are set: `Plan::fromEffectiveConfig($slug)` shall return each field as the override value when that field is non-null in the override row, and as the config value when the override field is null (or no override row exists). The merge is field-by-field and independent.

**Validates: Requirements 15.2, 15.9, 29.3**

---

### Property 7: Photo file cleanup on admin delete

*For any* photo record with all three paths (`original_path`, `optimized_path`, `thumbnail_path`) present on disk: after `PhotoRemovalService::remove($photo)`, the photo record shall be soft-deleted AND none of the three storage paths shall exist on `Storage::disk('public')`.

**Validates: Requirements 14.6, 25.4**

---

### Property 8: Admin responses do not expose secrets

*For any* request to any admin endpoint by a valid Super Admin, the Inertia JSON response shall not contain any of the following keys at any nesting depth: `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`, `original_path`, `optimized_path`, `thumbnail_path`, `provider_payment_id`, `secret_key`, `webhook_secret`.

**Validates: Requirements 24.3, 8.6, 14.5, 16.6, 17.6**

---

### Property 9: Plan limit changes do not cascade-delete content

*For any* user with a known count of events and photos: after updating `plan_overrides` to set lower limits for that user's plan, the event count and photo count for that user shall remain identical to the pre-update counts.

**Validates: Requirements 15.7, 29.3**

---

## Error Handling

### HTTP error map

| Scenario | HTTP status | Response |
|----------|-------------|----------|
| Guest requests `/admin/*` | 302 → `/login` | Handled by `auth` middleware before `EnsureSuperAdmin` |
| Organizer requests `/admin/*` | 403 | `EnsureSuperAdmin::abort(403)` — no stack trace |
| Inactive Super Admin requests `/admin/*` | 403 | Same as above |
| Inactive organizer requests organizer route | 403 | `EnsureActiveUser::abort(403)` |
| Resource not found | 404 | `findOrFail()` / route model binding |
| Form validation failure | 422 | Inertia error bag (field-level messages, consistent with Phase 1–12 pattern) |
| Business rule violation (last admin, self-promotion) | 422 | `abort(422, 'descriptive message')` |
| Unexpected server error | 500 | Generic message to frontend; full exception logged server-side via Laravel's logger |

### Secret / path non-leakage in error responses

The `withExceptions` handler in `bootstrap/app.php` already prevents stack traces in JSON for API paths. For Inertia responses, Laravel renders a generic error page in production. No exception message that contains filesystem paths, SQL, or secret keys is forwarded to the browser.

### Dangerous-action confirmation flow

Frontend dialog → user confirms → `useForm.post(route('admin.users.deactivate', user))` → backend validates → audit log → redirect with toast.

The backend enforces all rules independently of the dialog. The confirmation dialog is purely a UX safeguard (R21.5).

### Flash toast pattern (consistent with Phase 1–12)

```php
return back()->with('toast', ['type' => 'success', 'message' => 'User deactivated.']);
```
Frontend reads via `useFlashToast()` → Sonner.

---

## Testing Strategy

### Test files — `tests/Feature/Admin/`

| File | Properties / Correctness items covered |
|------|----------------------------------------|
| `AdminAccessTest.php` | Property 1 (role isolation), Property 4 (inactive enforcement), R25.1 |
| `UserManagementTest.php` | R25.2, Property 2 (self-promotion), Property 3 (last-admin) |
| `RoleManagementTest.php` | Property 2, Property 3, R10 |
| `EventManagementTest.php` | Property 5 (event isolation), R25.3 |
| `PhotoManagementTest.php` | Property 7 (file cleanup), R25.4 |
| `PlanManagementTest.php` | Property 6 (override correctness), Property 9 (no cascade), R25.5 |
| `SubscriptionPaymentTest.php` | R25.6 (read-only, no mutation) |
| `AuditLogTest.php` | All 9 audited actions, no-delete invariant (Property 8 partial), R25.7 |
| `AdminSecurityTest.php` | Property 8 (secret non-exposure), IDOR tests, R24, R25.8 |

### Testing approach per property

**Property 1 (Role isolation):** Use `ActingAs` with `User::factory()->create()` (no super_admin state). Hit representative endpoints (`GET /admin`, `GET /admin/users`, `DELETE /admin/photos/{uuid}`) and assert `->assertStatus(403)`.

**Property 2 & 3 (Self-promotion, last-admin):** Set up exact system state (1 super_admin). Issue the disallowed request. Assert 422 and that the DB state is unchanged (role count query).

**Property 4 (is_active enforcement):** `User::factory()->inactive()->create()`. Attempt login via `POST /login` — assert redirect back with errors (not authenticated). Use `actingAs($inactiveUser)` to simulate a stale session; hit organizer routes — assert 403.

**Property 5 (Event isolation):** Two organizer users, each with an event. Organizer A `actingAs` tries to `GET /events/{eventB:uuid}` — assert 403. Also verify that the Phase 1–12 `EventPolicy` tests still pass (regression).

**Property 6 (Plan override):** `PlanOverride::create(['slug'=>'free','max_active_events'=>5])`. Call `Plan::fromEffectiveConfig('free')`. Assert `maxActiveEvents === 5`, `maxPhotosPerEvent === config value` (null field falls back). Test with all fields null (full fallback) and all fields set (full override).

**Property 7 (Photo file cleanup):** Use `Storage::fake('public')`. Create fake files for all three paths. Call `PhotoRemovalService::remove($photo)`. Assert `$photo->fresh()->trashed()`, assert none of the three paths exist via `Storage::disk('public')->assertMissing(...)`.

**Property 8 (Secret non-exposure):** For each admin endpoint, decode the Inertia response JSON. Recursively search for forbidden keys. Assert none are present.

**Property 9 (No cascade on plan change):** Create user with 3 events and 10 photos. Update `plan_overrides` to `max_active_events=1`. Assert user still has 3 events and 10 photos in DB.

### Factories

- `User::factory()->superAdmin()` — creates user + assigns super_admin role
- `User::factory()->inactive()` — creates user with `is_active=false`
- `AuditLogFactory` — creates audit log records with sensible defaults

### Regression gate

After Phase 13 implementation, `php artisan test` must show 252 (existing) + N (new) passing tests, 0 failures. The organizer workflow, billing workflow, public pages, and auth flows are covered by existing tests that must not be modified.

### Manual bootstrap test

```bash
php artisan admin:create-admin
# → prompts email, name, password, confirm
# → assigns super_admin role
# → verify login at /admin works, organizer at /events still works
```

### Unit testing

Unit tests for:
- `User::isAdmin()` / `hasRole()` / `isActive()` with role fixtures
- `Plan::fromEffectiveConfig()` with null and non-null override combinations
- `AuditLogger::log()` — verify fields recorded correctly
- `PhotoRemovalService::remove()` — using `Storage::fake()`

### Testing framework

PHPUnit with `#[Test]` attributes, `RefreshDatabase`, `:memory:` SQLite. No real credentials, no cloud calls, no queue workers required.

---

## Design Decisions

### Decision A — Separate `roles` table (not a role column)

A `role` enum column on `users` would work for a two-role system today, but becomes a schema migration every time a new role is added. The `roles` / `user_roles` many-to-many design allows new roles (`support_staff`, `content_moderator`) to be seeded with no migration. The trade-off is a JOIN on every role check; this is acceptable because `hasRole()` checks are infrequent and the `user_roles` table has an index on `user_id`. User choice from the requirements document (Decision A in the Decisions Summary).

### Decision B — `is_active` boolean column (not a `status` enum)

A boolean is the simplest shape for a two-state (`active`/`inactive`) user status. An enum would add unnecessary migration complexity for states not in scope (e.g., `suspended`, `banned`). The boolean is also directly castable on the Eloquent model and trivially testable. User choice from the requirements document (Decision B).

### Decision C — `plan_overrides` table (not config edits)

Plan limits must be adjustable at runtime without a code deploy or server restart. Config files require a deployment; environment variables require a restart. A `plan_overrides` table allows admin UI edits that take effect immediately on the next `BillingService::currentPlan()` call. The `Plan::fromEffectiveConfig()` fallback to `config/plans.php` keeps Phase 1–12 behavior identical when the overrides table is empty.

### Decision D — `PhotoRemovalService` (not inline in controller)

`ProcessPhoto::failed()` already demonstrates the three-path deletion pattern. Extracting it into `PhotoRemovalService` makes the logic reusable (admin delete vs. potential future batch cleanup), testable in isolation with `Storage::fake()`, and avoids duplicating the storage paths logic. The service owns the "what to clean up" knowledge.

### Decision E — `Gate::before()` for global super-admin access

Registering `Gate::before(fn (User $u) => $u->isAdmin() ? true : null)` means every existing and future policy automatically grants Super Admins access. The alternative — adding `before()` to every policy class individually — requires remembering to update each new policy. The single `Gate::before()` in `AppServiceProvider` is the single source of truth.

### Decision F — `require routes/admin.php` in `routes/web.php`

Laravel 13's `Application::configure()->withRouting()` accepts a single `web:` file path; it does not accept an array of additional route files natively in the configuration closure. Using `require __DIR__.'/admin.php'` at the bottom of `web.php` is the pragmatic choice: it loads admin routes in the same web middleware context, respects the existing route cache, and avoids patching the framework bootstrap. The admin routes are a separate file for clarity; they are not merged into `web.php` inline.

### Decision G — `AdminLayout` fully separate from `AppLayout`

The admin area has a different navigation structure, different color/branding intent (distinct from organizer area), and must never show organizer nav links to avoid confusion. A shared layout with conditional logic would leak organizer concerns into the admin context. Complete separation keeps both layouts simple and independently maintainable.

### Decision H — Responsive card list pattern (not `<table>`)

No `components/ui/table.tsx` exists in the project. Phase 11 (Events index) and Phase 12 (Billing) use Card-based responsive lists. Adding raw HTML `<table>` would introduce an inconsistent pattern without a shadcn/ui table component. The Card list pattern is already mobile-friendly and consistent with the project's established UI conventions.

### Decision I — `AuditLog` no `updated_at` column

Audit logs are append-only by design. A `updated_at` column would imply records can be modified. Omitting it (via `$timestamps = false` and manually setting `created_at`) makes the immutability explicit at the schema level: any attempt to call `$log->update(...)` would silently fail to set a timestamp rather than succeed, and the lack of the column makes the intent clear to future developers.

### Decision J — `EnsureActiveUser` as a separate middleware (not inside `EnsureSuperAdmin`)

Organizer routes and admin routes have different middleware stacks. Bundling the `is_active` check into `EnsureSuperAdmin` would only enforce it on admin routes. A separate `EnsureActiveUser` middleware applied to the organizer `['auth', 'verified']` group enforces the is_active rule at the correct level for organizers — without touching the admin middleware chain.
