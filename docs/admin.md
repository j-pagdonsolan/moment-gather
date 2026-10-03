# MomentGather Admin System

Developer reference for the Phase 13 admin system.

---

## 1. Overview

Phase 13 adds a **Super Admin** role and a full admin panel at `/admin`.

**Role model:**
- `super_admin` — full access to all admin sections.
- No explicit organizer role. Every authenticated, verified, active user is an organizer by default.

**User status (`is_active`):**
- Added as a boolean column (default `true`) on the `users` table.
- Enforced at **login**: the Fortify authentication callback (`FortifyServiceProvider`) returns `null` for inactive users, treating deactivation the same as a wrong password — no error distinction is given to the user.
- Enforced **mid-session** on organizer routes: the `active` middleware alias (`EnsureActiveUser`) aborts with 403 if a logged-in user's `is_active` is `false`. This blocks deactivated users who already have a session open.

---

## 2. Bootstrap: Creating the First Super Admin

```bash
php artisan admin:create-admin
```

**Behavior:**

1. Prompts for an email address.
2. If the user **already exists**, promotes them to `super_admin` immediately (no name/password prompts).
3. If the user **does not exist**, prompts for full name, password, and confirmation, then creates the account and assigns the `super_admin` role.

**Example (new user):**

```
$ php artisan admin:create-admin
Email address: admin@example.com
Full name: Alice Admin
Password (hidden): ••••••••••••
Confirm password (hidden): ••••••••••••
Super Admin 'admin@example.com' created successfully.
```

After running the command, log in at `/login` and navigate to `/admin`.

---

## 3. Admin Routes

All routes are defined in `routes/admin.php`, required at the bottom of `routes/web.php`, and share the middleware stack `['auth', 'verified', 'admin']`.

Route model binding: `{user}` binds `User` by primary key; `{event:uuid}` and `{photo:uuid}` bind by their `uuid` column.

| Method | URI | Name | Purpose |
|--------|-----|------|---------|
| GET | `/admin` | `admin.dashboard` | Stats overview (user/event/photo counts) |
| GET | `/admin/users` | `admin.users.index` | Paginated user list with role and status filters |
| GET | `/admin/users/{user}` | `admin.users.show` | User detail: profile, roles, subscriptions, events |
| POST | `/admin/users/{user}/activate` | `admin.users.activate` | Set `is_active = true` |
| POST | `/admin/users/{user}/deactivate` | `admin.users.deactivate` | Set `is_active = false` (blocked if last super_admin) |
| POST | `/admin/users/{user}/roles/assign` | `admin.users.roles.assign` | Add a role to a user |
| POST | `/admin/users/{user}/roles/remove` | `admin.users.roles.remove` | Remove a role from a user |
| GET | `/admin/events` | `admin.events.index` | Paginated event list across all users |
| GET | `/admin/events/{event:uuid}` | `admin.events.show` | Event detail with photos |
| POST | `/admin/events/{event:uuid}/archive` | `admin.events.archive` | Set status to `archived` |
| DELETE | `/admin/events/{event:uuid}` | `admin.events.destroy` | Hard delete event and its photos |
| GET | `/admin/photos` | `admin.photos.index` | Paginated photo list across all events |
| GET | `/admin/photos/{photo:uuid}` | `admin.photos.show` | Photo detail |
| DELETE | `/admin/photos/{photo:uuid}` | `admin.photos.destroy` | Delete photo and its storage files |
| GET | `/admin/plans` | `admin.plans.index` | List all plans (config + override values) |
| PUT | `/admin/plans/{slug}` | `admin.plans.update` | Update plan override limits |
| POST | `/admin/plans/{slug}/deactivate` | `admin.plans.deactivate` | Set plan `is_active = false` |
| GET | `/admin/subscriptions` | `admin.subscriptions.index` | Paginated subscription list (read-only) |
| GET | `/admin/subscriptions/{subscription}` | `admin.subscriptions.show` | Subscription detail (read-only) |
| GET | `/admin/payments` | `admin.payments.index` | Paginated payment list (read-only) |
| GET | `/admin/payments/{payment}` | `admin.payments.show` | Payment detail (read-only) |
| GET | `/admin/audit-logs` | `admin.audit-logs.index` | Paginated audit log list (read-only, no delete) |
| GET | `/admin/audit-logs/{log}` | `admin.audit-logs.show` | Audit log entry detail |

---

## 4. Middleware Stack

**Admin routes** (`/admin/*`):

```
auth → verified → admin (EnsureSuperAdmin)
```

- `auth` — redirects unauthenticated requests to `/login`.
- `verified` — redirects unverified email accounts to the email verification page.
- `admin` (`EnsureSuperAdmin`) — aborts with **403** if the user does not have the `super_admin` role, or if `is_active` is `false`.

**Organizer routes** (`/dashboard`, `/events/*`, `/billing/*`, etc.):

```
auth → verified → active (EnsureActiveUser)
```

- `active` (`EnsureActiveUser`) — aborts with **403** if an authenticated user's `is_active` is `false`. Unauthenticated requests are passed through (handled upstream by `auth`).

Both middlewares are registered as aliases in `bootstrap/app.php`:

```php
$middleware->alias([
    'admin'  => \App\Http\Middleware\EnsureSuperAdmin::class,
    'active' => \App\Http\Middleware\EnsureActiveUser::class,
]);
```

---

## 5. Gate Abilities

Defined in `AppServiceProvider::boot()` via `Gate::define()`.

```php
// Grant all policy checks to super admins globally (bypasses all policies)
Gate::before(function (User $user, string $ability): ?bool {
    return $user->loadMissing('roles')->isAdmin() ? true : null;
});

// Named gates for admin sections
Gate::define('manage-users',         fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('manage-events',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('manage-photos',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('manage-plans',         fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('manage-subscriptions', fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('view-payments',        fn (User $u) => $u->loadMissing('roles')->isAdmin());
Gate::define('view-audit-logs',      fn (User $u) => $u->loadMissing('roles')->isAdmin());
```

All 7 abilities return `true` for `super_admin` users and `false` for everyone else.

**Calling a gate in a controller:**

```php
Gate::authorize('manage-users'); // throws 403 AuthorizationException if not super_admin
```

**`Gate::before()` effect:** super admins pass every `Gate::check()` / `Gate::authorize()` / policy check automatically, without touching the individual gate definitions.

---

## 6. Audit Log

All admin mutations are recorded to the `audit_logs` table via the `AuditLogger` service (`app/Services/AuditLogger.php`).

### Schema

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `user_id` | bigint unsigned nullable | FK → `users.id` **SET NULL** on delete |
| `action` | varchar(100) | Snake-case action identifier |
| `target_type` | varchar(100) nullable | Model short name, e.g. `'User'`, `'Event'` |
| `target_id` | bigint unsigned nullable | PK of the affected record |
| `description` | text | Human-readable description |
| `metadata` | json nullable | Additional context (see restrictions below) |
| `ip_address` | varchar(45) nullable | Null for CLI-originated actions |
| `user_agent` | text nullable | Null for CLI-originated actions |
| `created_at` | timestamp | No `updated_at` — append-only |

`user_id` uses **SET NULL** (not CASCADE) so audit records are preserved when the actor user is deleted.

### Audited Action Strings

```
user_activated      user_deactivated    role_assigned       role_removed
event_archived      event_deleted       photo_deleted
plan_limit_updated  plan_deactivated
```

### Example Usage

```php
$this->auditLogger->log(
    actor: $request->user(),
    action: 'user_activated',
    targetType: 'User',
    targetId: $user->id,
    description: "Activated user {$user->email}.",
    metadata: ['target_email' => $user->email],
);
```

### Metadata Restrictions

The `metadata` field **must never contain**:
- Passwords or password hashes
- Secrets (`two_factor_secret`, `webhook_secret`, `secret_key`, etc.)
- Raw storage paths (`original_path`, `optimized_path`, `thumbnail_path`)
- `provider_payment_id` or `provider_subscription_id`
- `remember_token` or `two_factor_recovery_codes`

### Append-Only

No `DELETE` endpoint exists for audit logs. Records may only be created, never removed. View at `/admin/audit-logs`.

---

## 7. Plan Overrides

Plans are defined in `config/plans.php` as the base configuration. Admins can adjust individual limits per plan at runtime using the `plan_overrides` table (`PlanOverride` model), without requiring a code deploy.

**Resolution order:** override field wins when non-null; otherwise the config value is used.

```php
// Reads base config only — no DB query
$plan = Plan::fromConfig('pro');

// Merges config + plan_overrides row (one DB query) — override wins per non-null field
$plan = Plan::fromEffectiveConfig('pro');

echo $plan->maxActiveEvents;   // effective value (override or config)
echo $plan->maxPhotosPerEvent;
echo $plan->maxStorageBytes;
echo $plan->price;
```

**Deactivating a plan** sets `is_active = false` on the `PlanOverride` row. It does **not** delete user content or cancel existing subscriptions.

---

## 8. Security Notes

- **Passwords and secrets** — `password`, `two_factor_secret`, and `remember_token` are in the `$hidden` array on `User` and never appear in admin API responses (R8.6).
- **Storage paths** — `original_path`, `optimized_path`, and `thumbnail_path` are never included in photo API responses (R14.5).
- **Billing identifiers** — `provider_subscription_id` and `provider_payment_id` are never included in billing API responses.
- **Self-promotion blocked** — an admin cannot assign or remove roles on their own account (R10.4).
- **Last Super Admin protection** — the system prevents deactivating or removing the `super_admin` role from the last active super admin (R9.2, R10.3). This check is enforced in `UserController` before any state change.

### Checking `hasRole()` in application code

```php
$user->loadMissing('roles'); // eager-load to avoid N+1
if ($user->hasRole('super_admin')) {
    // ...
}

// Shorthand (calls hasRole internally)
if ($user->isAdmin()) {
    // ...
}
```

---

## 9. Running Tests

```bash
php artisan test --filter=Admin
```

Expected: **50 tests, 0 failures** across:

| Test File | Coverage |
|-----------|----------|
| `AdminAccessTest` | Middleware enforcement (guest, organizer, inactive admin) |
| `UserManagementTest` | User list, show, activate, deactivate |
| `RoleManagementTest` | Assign/remove roles, self-promotion block, last-admin guard |
| `EventManagementTest` | Event list, show, archive, destroy |
| `PhotoManagementTest` | Photo list, show, destroy |
| `PlanManagementTest` | Plan list, update limits, deactivate |
| `SubscriptionPaymentTest` | Subscription and payment read-only views |
| `AuditLogTest` | Audit log list and detail |
| `AdminSecurityTest` | Sensitive field exclusion, IDOR prevention |
