# Requirements Document

## Introduction

Phase 13 adds a secure administrative system to MomentGather, a Laravel 13 + React 19 + Inertia v3 + TypeScript + Tailwind v4 event photo-sharing SaaS. Phases 1–12 are complete (252 backend tests passing). This phase introduces role-based access control, a user-status mechanism, a dedicated admin area with its own layout, and full administrative capabilities over users, events, photos, plans, subscriptions, payments, and an audit log — without rebuilding any Phase 1–12 feature or introducing Redis, Horizon, cloud services, or AI.

All Phase 1–12 behavior is explicitly preserved. The organizer experience is unchanged. Super Admin access is additive and intentional.

---

## Decisions Summary

| ID | Decision | Choice |
|----|----------|--------|
| A | Role storage | Separate `roles` table (id, name, display_name, timestamps) + `user_roles` pivot table (user_id FK, role_id FK, composite PK). Many-to-many via `belongsToMany` on User. Extensible without migrations for new roles. |
| B | User status | `is_active` boolean column on `users` table (default `true`). Fortify-compatible authentication pipeline check blocks login for inactive users. Deactivation preserves all data. |

---

## New vs Existing Summary

| Component | Status | Notes |
|-----------|--------|-------|
| `roles` table | **New** | Role catalog — `super_admin`, `organizer` seeded |
| `user_roles` pivot table | **New** | Many-to-many link between users and roles |
| `is_active` column on `users` | **New** | Boolean, default `true`; blocks login when `false` |
| `plan_overrides` table | **New** | Admin-editable per-plan limit overrides; `BillingService` checks here first |
| `audit_logs` table | **New** | Append-only record of admin actions |
| `User::hasRole()` / `isAdmin()` / `isActive()` | **New** | Helpers on existing `User` model |
| `EnsureSuperAdmin` middleware | **New** | Added to middleware stack; guards all `/admin/*` routes |
| `AuditLogger` service | **New** | New service; records admin actions with actor + target |
| `AdminAuthorization` service | **New** | Central authorization service; also `before()` hook on all policies |
| `EventPolicy::before()` | **Modified** | Grants Super Admin all event permissions; owner check preserved for organizers |
| `HandleInertiaRequests::share()` | **Modified** | Extended to include `auth.user.role` for frontend nav visibility |
| `BillingService` | **Modified** | Checks `plan_overrides` table before falling back to `config/plans.php` |
| `app.tsx` layout resolver | **Modified** | `Admin/*` pages resolve to new `AdminLayout` |
| `routes/admin.php` | **New** | Dedicated admin route file, all under `['auth','verified','admin']` |
| `AdminLayout` (React) | **New** | Separate layout for admin area; distinct from `AppLayout` |
| `php artisan admin:create-admin` | **New** | Interactive Artisan command to bootstrap the first Super Admin |
| `UserFactory` states | **Modified** | Added `superAdmin()` and `inactive()` states |
| `AuditLogFactory` | **New** | Factory for audit_log test records |
| `docs/admin.md` | **New** | Documentation for roles, permissions, admin bootstrap, architecture |
| All existing controllers / models / routes | **Unchanged** | No organizer feature is removed or altered |

---

## Glossary

- **Super_Admin**: A User with the `super_admin` role. Has unrestricted access to the admin area and can perform all administrative actions. Cannot self-promote or demote the last Super Admin.
- **Organizer**: A User without the `super_admin` role. The standard authenticated user role for creating and managing events. In Phase 13 an explicit `organizer` role record is seeded, but the existing behavior of any authenticated user being an organizer is preserved.
- **Role**: A named record in the `roles` table (e.g. `super_admin`, `organizer`) with a human-readable `display_name`.
- **Role Slug**: The machine-readable `name` column on the `roles` table, e.g. `super_admin`.
- **User_Roles Pivot**: The `user_roles` table linking `user_id` to `role_id` with a composite primary key.
- **is_active**: A boolean column on the `users` table (default `true`). When `false`, the user cannot log in and cannot perform organizer actions, but all their data is preserved.
- **AdminAuthorization**: The central service (and/or policy `before()` hook) that short-circuits all Laravel Gate policy checks for Super Admins, granting them all permissions.
- **EnsureSuperAdmin**: A Laravel middleware that checks whether the authenticated user has the `super_admin` role; returns HTTP 403 for all others.
- **AuditLogger**: A service that appends records to the `audit_logs` table for significant admin actions.
- **AuditLog**: An append-only record in the `audit_logs` table capturing who did what to whom, when, and from where.
- **plan_overrides**: A database table that stores admin-edited overrides for plan limits (keyed by plan slug). `BillingService` checks this table first before falling back to `config/plans.php`.
- **AdminLayout**: A dedicated React layout component for admin pages, distinct from `AppLayout`. Resolves for all `Admin/*` Inertia page names in `app.tsx`.
- **Admin Dashboard**: The `/admin` page; shows aggregate system statistics.
- **EventPolicy**: The existing Laravel Gate policy (`app/Policies/EventPolicy.php`). Modified in Phase 13 to add a `before()` method granting Super Admin all event permissions.
- **BillingService**: The existing `app/Billing/BillingService.php`. Modified in Phase 13 to consult `plan_overrides` before `config/plans.php`.
- **HandleInertiaRequests**: The existing `app/Http/Middleware/HandleInertiaRequests.php`. Modified in Phase 13 to include `auth.user.roles` in shared props.
- **app.tsx**: The Inertia application entry point (`resources/js/app.tsx`). Modified in Phase 13 to route `Admin/*` to `AdminLayout`.
- **Soft Delete**: Laravel's mechanism that sets `deleted_at` rather than removing the row; already used by `Event` and `Photo` models.
- **ProcessPhoto Job**: The existing `app/Jobs/ProcessPhoto.php` job that handles photo variant generation; its `failed()` method cleans up partial variants.
- **Pagination**: Server-side paginated responses, 15–25 records per page, for all admin list views.

---

## Out of Scope

The following are explicitly excluded from Phase 13:

- **Redis / Laravel Horizon / queue infrastructure changes**: No new queue drivers or workers.
- **Cloud storage / S3 / CDK / Terraform**: Local filesystem only; no cloud deployment configuration.
- **AI / machine learning features**: No AI-assisted moderation, content analysis, or recommendations.
- **Real payment provider integration**: No changes to the Stripe/payment-provider layer; admin subscription/payment views are read-only.
- **Multi-tenancy**: No tenant isolation beyond the existing user-scoped ownership model.
- **CAPTCHA or bot protection**: Out of scope for this phase.
- **GDPR / data-export automation**: No automated personal-data export or right-to-erasure workflows.
- **Granular per-resource permissions**: No per-event or per-photo ACLs beyond what the Super Admin / Organizer roles provide.
- **Admin-initiated payments**: Admins can view payment records but cannot create, refund, or modify payments through the admin UI.
- **Email notifications for admin actions**: No automated email is sent when a user is deactivated, a role is changed, or a plan is updated.
- **Public Phase 13 announcement or marketing pages**: Out of scope.

---

## Requirements

### Requirement 1: Role Structure

**User Story:** As a system architect, I want roles stored in a dedicated database table with a many-to-many pivot to users, so that the system can support multiple named roles without schema migrations for each new role.

#### Acceptance Criteria

1. THE System SHALL maintain a `roles` table with columns: `id` (auto-increment PK), `name` (unique varchar, the role slug), `display_name` (varchar), `created_at`, `updated_at`.
2. THE System SHALL maintain a `user_roles` pivot table with columns: `user_id` (FK → `users.id`, cascade delete), `role_id` (FK → `roles.id`, cascade delete), with a composite primary key on `(user_id, role_id)`.
3. THE System SHALL seed the `roles` table with exactly two records on initial migration: `super_admin` (display_name "Super Admin") and `organizer` (display_name "Organizer").
4. THE User Model SHALL expose a `roles()` `belongsToMany` relationship pointing to the `roles` table via the `user_roles` pivot.
5. THE User Model SHALL provide a `hasRole(string $slug): bool` helper that returns `true` when the user is linked to the role with the given `name` slug.
6. THE User Model SHALL provide an `isAdmin(): bool` helper that returns `true` when `hasRole('super_admin')` is `true`.
7. WHEN a new role is seeded into the `roles` table, THE System SHALL not require any schema migration to assign that role to users.
8. THE `roles.name` column SHALL have a unique database index.
9. THE `user_roles.user_id` column SHALL have a database index.

---

### Requirement 2: User Status (is_active)

**User Story:** As a Super Admin, I want to activate or deactivate user accounts, so that I can suspend access without permanently deleting user data.

#### Acceptance Criteria

1. THE `users` table SHALL have an `is_active` boolean column with a default value of `true`.
2. THE User Model SHALL provide an `isActive(): bool` helper that returns the value of `is_active`.
3. WHEN a user's `is_active` is set to `false`, THE System SHALL preserve all data belonging to that user (events, photos, subscriptions, payments) without any cascade deletion.
4. WHEN a user's `is_active` is `false` and that user attempts to log in via any Fortify-supported mechanism (email/password, passkey, 2FA continuation), THE Authentication Pipeline SHALL reject the login attempt and return an authentication error to the user.
5. WHEN a Super Admin deactivates a user, THE System SHALL NOT invalidate any currently active Fortify sessions for that user in real time (session invalidation is out of scope for this phase).
6. WHEN a Super Admin reactivates a user (sets `is_active` to `true`), THE Authentication Pipeline SHALL permit the user to log in again.

---

### Requirement 3: Super Admin Bootstrap Command

**User Story:** As a system operator, I want an Artisan command to create the first Super Admin account, so that I can bootstrap administrator access without needing a pre-existing admin in the database.

#### Acceptance Criteria

1. THE System SHALL provide an Artisan command with the signature `admin:create-admin`.
2. WHEN `admin:create-admin` is executed, THE Command SHALL interactively prompt for: (a) email address, (b) full name, (c) password (hidden input), (d) password confirmation (hidden input).
3. WHEN the supplied email already belongs to an existing user, THE Command SHALL assign the `super_admin` role to that existing user without changing the user's password or other attributes, and SHALL display a confirmation message.
4. WHEN the supplied email does not exist, THE Command SHALL create a new User record with the given name, email, and hashed password, mark the user as `is_active = true`, assign the `super_admin` role, and display a confirmation message.
5. WHEN the supplied email does not exist and the password and password confirmation do not match, THE Command SHALL display a validation error and exit without creating any records.
6. WHEN the supplied email does not exist and the supplied email fails email-format validation, THE Command SHALL display a validation error and exit without creating any records.
7. THE Command SHALL NOT print the plaintext password at any point in its output.
8. THE Command SHALL NOT be invocable through any HTTP endpoint or admin UI; it is a CLI-only operation.
9. THE Command SHALL be placed in the `App\Console\Commands` namespace, following the existing `ProcessPhotos` command convention.

---

### Requirement 4: Authorization Architecture

**User Story:** As a developer, I want a central authorization service that grants Super Admins all permissions without scattering role checks across controllers and policies, so that the system is maintainable and privilege escalation is auditable.

#### Acceptance Criteria

1. THE System SHALL implement an `AdminAuthorization` service (or equivalent policy `before()` mechanism) that, when the authenticated user `isAdmin()`, short-circuits all Laravel Gate policy checks and returns `true` unconditionally.
2. THE `EventPolicy` SHALL be modified to include a `before(User $user, string $ability): ?bool` method that returns `true` when `$user->isAdmin()`, delegating to the existing owner-equality checks for non-admin users.
3. THE System SHALL define Laravel Gates for the following admin capabilities: `manage-users`, `manage-events`, `manage-photos`, `manage-plans`, `manage-subscriptions`, `view-payments`, `view-audit-logs`.
4. THE Gate definitions SHALL be registered in a service provider (e.g. `AppServiceProvider` or a new `AdminServiceProvider`) and SHALL check `$user->isAdmin()`.
5. WHEN a user who is not a Super Admin attempts to authorize any of the Gates listed in criterion 3, THE Gate SHALL return `false`.
6. THE System SHALL NOT use scattered `if ($user->hasRole('super_admin'))` checks in controllers; all privilege decisions SHALL be routed through Gate or policy calls.
7. WHEN the `AdminAuthorization` `before()` hook grants access to a Super Admin, THE action SHALL be eligible for audit logging by the `AuditLogger` service.

---

### Requirement 5: Admin Route Protection

**User Story:** As a security engineer, I want all `/admin/*` routes protected by a dedicated middleware, so that a single enforcement point prevents non-admin access regardless of controller logic.

#### Acceptance Criteria

1. THE System SHALL provide an `EnsureSuperAdmin` middleware that verifies the authenticated user `isAdmin()`.
2. WHEN a guest (unauthenticated) user requests any `/admin/*` route, THE Application SHALL redirect to `/login` (handled by the existing `auth` middleware before `EnsureSuperAdmin` is reached).
3. WHEN an authenticated Organizer (non-Super Admin) requests any `/admin/*` route, THE `EnsureSuperAdmin` middleware SHALL abort with HTTP 403.
4. WHEN an authenticated Super Admin requests any `/admin/*` route, THE `EnsureSuperAdmin` middleware SHALL permit the request to proceed.
5. THE System SHALL register all admin routes in a dedicated file `routes/admin.php`, loaded in the application bootstrap, under the middleware stack `['auth', 'verified', 'admin']` where `'admin'` resolves to `EnsureSuperAdmin`.
6. NO admin authorization logic SHALL be duplicated inside individual admin controllers; all protection SHALL be enforced at the middleware and Gate/policy layer.
7. THE `EnsureSuperAdmin` middleware SHALL also verify `isActive()` on the authenticated user; IF the user's `is_active` is `false`, THE middleware SHALL abort with HTTP 403.

---

### Requirement 6: Admin Layout

**User Story:** As a Super Admin, I want a dedicated admin layout with its own navigation sidebar, so that the admin area is visually distinct from the organizer area and provides clear access to all admin sections.

#### Acceptance Criteria

1. THE System SHALL provide a new `AdminLayout` React component (`resources/js/layouts/admin-layout.tsx`) that is visually and structurally distinct from `AppLayout`.
2. THE `app.tsx` layout resolver SHALL be modified to return `AdminLayout` for all Inertia page names starting with `Admin/`.
3. THE `AdminLayout` sidebar navigation SHALL include links to: Dashboard (`/admin`), Users (`/admin/users`), Events (`/admin/events`), Photos (`/admin/photos`), Plans (`/admin/plans`), Subscriptions (`/admin/subscriptions`), Payments (`/admin/payments`), Audit Logs (`/admin/audit-logs`).
4. THE `AdminLayout` SHALL visually highlight the currently active navigation section.
5. THE `AdminLayout` SHALL NOT include any organizer navigation items (event management links, billing links, etc.).
6. THE `AdminLayout` SHALL be mobile-friendly using existing Tailwind v4 and shadcn/ui primitives already present in the project.
7. WHEN a page resolves to `AdminLayout`, THE organizer `AppLayout` SHALL NOT be rendered for that page.
8. THE organizer `AppLayout` SHALL continue to resolve for all non-`Admin/*` pages, preserving all Phase 1–12 frontend behavior.

---

### Requirement 7: Admin Dashboard

**User Story:** As a Super Admin, I want a dashboard with aggregate statistics, so that I can quickly assess system health and usage without running manual queries.

#### Acceptance Criteria

1. THE Admin Dashboard SHALL display user statistics: total user count, count of active users (`is_active = true`), count of inactive users (`is_active = false`), count of Super Admin users.
2. THE Admin Dashboard SHALL display event statistics: total event count (excluding soft-deleted), count of `active` status events, count of `draft` status events, count of `archived` status events.
3. THE Admin Dashboard SHALL display photo statistics: total photo count (excluding soft-deleted), count by status (`pending`, `processing`, `ready`, `failed`).
4. THE Admin Dashboard SHALL display subscription statistics: count of free-plan users (no active subscription), count of pro-plan users (active subscription), count of `active` status subscriptions, count of `canceled` status subscriptions.
5. THE Admin Dashboard SHALL display payment statistics: count of `succeeded` payments, count of `failed` payments, and the 5 most recent payments (user name/email, amount, currency, status, date).
6. THE Dashboard data SHALL be computed using aggregate SQL queries (COUNT, GROUP BY) with no N+1 query patterns; a single controller action SHALL load all statistics efficiently.
7. THE Admin Dashboard SHALL be accessible at `GET /admin`.

---

### Requirement 8: User Management — Listing and Detail

**User Story:** As a Super Admin, I want to list, search, filter, and view users, so that I can manage accounts efficiently.

#### Acceptance Criteria

1. THE User List page SHALL be accessible at `GET /admin/users` and SHALL return a paginated list of users (15 per page).
2. THE User List SHALL support search by name or email (case-insensitive partial match).
3. THE User List SHALL support filtering by role (all / super_admin / organizer) and by `is_active` status (all / active / inactive).
4. WHEN a Super Admin views the User List, EACH record SHALL include: id, name, email, `is_active`, role names, plan (free/pro), event count, created_at.
5. THE User Detail page SHALL be accessible at `GET /admin/users/{user}` and SHALL display: account info (name, email, email_verified_at, created_at, updated_at), role, `is_active` status, current plan and subscription status, event count, photo count, storage used (bytes), 2FA enabled status.
6. THE User Detail response SHALL NOT expose: password hash, two_factor_secret, two_factor_recovery_codes, remember_token, provider payment secrets, or any card data.
7. WHEN a Super Admin requests a user detail page and the user does not exist, THE System SHALL return HTTP 404.

---

### Requirement 9: User Management — Activate / Deactivate

**User Story:** As a Super Admin, I want to activate or deactivate user accounts from the admin UI, so that I can suspend access without deleting data.

#### Acceptance Criteria

1. THE System SHALL expose a `POST /admin/users/{user}/activate` endpoint that sets `is_active = true` for the target user.
2. THE System SHALL expose a `POST /admin/users/{user}/deactivate` endpoint that sets `is_active = false` for the target user.
3. WHEN a Super Admin attempts to deactivate a user who holds the last remaining active `super_admin` role assignment in the system, THE System SHALL reject the request with HTTP 422 and a descriptive error message.
4. WHEN a Super Admin deactivates or activates a user, THE `AuditLogger` SHALL record a `user_deactivated` or `user_activated` entry including actor ID, target user ID, IP address, and user agent.
5. WHEN a deactivate or activate action succeeds, THE System SHALL return a redirect or Inertia response that refreshes the user detail page with a success message.
6. A dangerous-action confirmation dialog SHALL be displayed in the frontend before submitting a deactivate request, explaining the consequence that the user will be unable to log in.

---

### Requirement 10: User Management — Role Assignment

**User Story:** As a Super Admin, I want to assign or remove the Super Admin role for users, so that I can manage administrator access with appropriate safeguards.

#### Acceptance Criteria

1. THE System SHALL expose a `POST /admin/users/{user}/roles/assign` endpoint accepting a `role` parameter (role slug) that adds the specified role to the target user.
2. THE System SHALL expose a `POST /admin/users/{user}/roles/remove` endpoint accepting a `role` parameter (role slug) that removes the specified role from the target user.
3. WHEN a Super Admin attempts to assign the `super_admin` role to themselves (actor ID = target user ID), THE System SHALL reject the request with HTTP 422 and a descriptive error ("Cannot modify your own role").
4. WHEN a Super Admin attempts to remove the `super_admin` role from a user and that user is the last remaining user with the `super_admin` role, THE System SHALL reject the request with HTTP 422 and a descriptive error ("Cannot remove the last Super Admin").
5. WHEN a role assignment or removal succeeds, THE `AuditLogger` SHALL record a `role_assigned` or `role_removed` entry including actor ID, target user ID, role slug, IP address, and user agent.
6. WHEN a non-Super Admin user requests either endpoint, THE `EnsureSuperAdmin` middleware SHALL return HTTP 403.
7. A dangerous-action confirmation dialog SHALL be presented in the frontend before submitting a role-change request.

---

### Requirement 11: User Status Enforcement for Organizer Actions

**User Story:** As a security engineer, I want inactive users to be blocked from performing organizer actions, so that deactivation takes full effect even within active sessions.

#### Acceptance Criteria

1. WHEN an authenticated user with `is_active = false` sends a request to any organizer route (under the `['auth', 'verified']` middleware group), THE System SHALL abort with HTTP 403.
2. THE enforcement of `is_active` for organizer routes SHALL be implemented as a middleware or pipeline step that is applied to all organizer routes, not scattered in individual controllers.
3. WHEN an authenticated user with `is_active = true` sends a request to any organizer route, THE System SHALL process the request normally without any change to Phase 1–12 behavior.
4. THE `is_active` check on organizer routes SHALL NOT affect the admin area; Super Admin users with `is_active = true` access admin routes normally.

---

### Requirement 12: Event Management

**User Story:** As a Super Admin, I want to list, search, filter, view, archive, and delete any event, so that I can maintain content quality across all organizers' events.

#### Acceptance Criteria

1. THE Event List page SHALL be accessible at `GET /admin/events` and SHALL return a paginated list of all events (excluding soft-deleted) across all organizers (20 per page).
2. THE Event List SHALL support search by event name or slug (case-insensitive partial match).
3. THE Event List SHALL support filtering by status (`active`, `draft`, `archived`) and by owner (user ID or email).
4. EACH record in the Event List SHALL include: id, uuid, name, slug, status, owner name/email, photo count, created_at.
5. THE Event Detail page SHALL be accessible at `GET /admin/events/{event:uuid}` and SHALL display: event fields (name, slug, description, event_date, location, status, upload_enabled, created_at, updated_at), owner info (name, email), photo count.
6. THE System SHALL expose a `POST /admin/events/{event:uuid}/archive` endpoint that sets the event's `status` to `archived`.
7. THE System SHALL expose a `DELETE /admin/events/{event:uuid}` endpoint that soft-deletes the event following the same data-integrity rules as the organizer delete action.
8. WHEN a Super Admin archives or deletes an event, THE `AuditLogger` SHALL record an `event_archived` or `event_deleted` entry including actor ID, event UUID, IP address, and user agent.
9. WHEN a Super Admin archives or deletes an event, THE organizer who owns that event SHALL retain the event in their own view consistent with soft-delete behavior (archived = visible, soft-deleted = not visible to organizer queries).
10. WHEN a Super Admin requests an event that does not exist or is already soft-deleted, THE System SHALL return HTTP 404.
11. A dangerous-action confirmation dialog SHALL be displayed before submitting an event delete request, explaining the action is irreversible from the admin UI.

---

### Requirement 13: Event Isolation Preservation

**User Story:** As an organizer, I want my event management experience to be unchanged by the admin system, so that I can trust that only I and authorized admins can see my events through organizer routes.

#### Acceptance Criteria

1. WHEN an authenticated Organizer requests `GET /events` or `GET /events/{event:uuid}`, THE `EventPolicy` SHALL continue to enforce owner-equality (`$user->id === $event->user_id`) for non-admin users, returning HTTP 403 for mismatched ownership.
2. THE `EventPolicy::before()` method added in Phase 13 SHALL only grant additional permissions to Super Admins; it SHALL NOT change behavior for any other user.
3. WHEN a Super Admin accesses an organizer's event via an admin route (`/admin/events/*`), THE action SHALL be routed through the admin controllers, not the organizer `EventController`.
4. THE organizer `EventController` SHALL NOT be modified to accept or handle Super Admin requests; admin event actions are implemented in separate admin controllers.

---

### Requirement 14: Photo Management

**User Story:** As a Super Admin, I want to list, filter, view, and delete photos across all events, so that I can remove inappropriate or corrupt content.

#### Acceptance Criteria

1. THE Photo List page SHALL be accessible at `GET /admin/photos` and SHALL return a paginated list of all photos (excluding soft-deleted) across all events (20 per page).
2. THE Photo List SHALL support filtering by event (UUID or ID), by photo status (`pending`, `processing`, `ready`, `failed`), and by owner (user ID or email).
3. EACH record in the Photo List SHALL include: id, uuid, original_filename, mime_type, file_size, status, event name, owner name/email, created_at.
4. THE Photo Detail page SHALL be accessible at `GET /admin/photos/{photo:uuid}` and SHALL display: uuid, original_filename, mime_type, file_size, width, height, status, event (name, uuid), owner (name, email), created_at, updated_at.
5. THE Photo Detail response SHALL NOT expose: `original_path`, `optimized_path`, `thumbnail_path`, or any raw filesystem path.
6. THE System SHALL expose a `DELETE /admin/photos/{photo:uuid}` endpoint that soft-deletes the photo record and removes the associated storage files using the same cleanup logic as the existing `ProcessPhoto` job's `failed()` method.
7. WHEN a Super Admin deletes a photo, THE `AuditLogger` SHALL record a `photo_deleted` entry including actor ID, photo UUID, event UUID, IP address, and user agent.
8. WHEN a Super Admin requests a photo that does not exist or is already soft-deleted, THE System SHALL return HTTP 404.
9. WHEN a Super Admin deletes a photo via the admin route, THE organizer who owns the event SHALL no longer see that photo in the gallery (consistent with soft-delete behavior).
10. AN Organizer SHALL NOT be able to access another organizer's photos via any admin route; admin routes are protected by `EnsureSuperAdmin`.
11. A dangerous-action confirmation dialog SHALL be displayed before submitting a photo delete request.

---

### Requirement 15: Plan Management

**User Story:** As a Super Admin, I want to view and override plan limits at runtime, so that I can adjust the product without deploying code changes.

#### Acceptance Criteria

1. THE System SHALL maintain a `plan_overrides` table with columns: `id`, `slug` (unique varchar, matches a plan slug from `config/plans.php`), `max_active_events` (nullable int), `max_photos_per_event` (nullable int), `max_storage_bytes` (nullable bigint), `price` (nullable int, minor currency units), `is_active` (boolean, default `true`), `created_at`, `updated_at`.
2. THE `BillingService` SHALL check the `plan_overrides` table for a matching `slug` first; for each limit field, if the override row exists and the field is non-null, it SHALL use the override value; otherwise it SHALL fall back to the value from `config/plans.php`.
3. THE Plan Management page SHALL be accessible at `GET /admin/plans` and SHALL display the effective limits for each plan (free and pro), indicating which values come from `plan_overrides` and which come from `config/plans.php`.
4. THE System SHALL expose a `PUT /admin/plans/{slug}` endpoint that creates or updates a `plan_overrides` row for the given slug with the submitted limit values.
5. WHEN a Super Admin updates plan limits, THE `AuditLogger` SHALL record a `plan_limit_updated` entry including actor ID, plan slug, changed fields with old and new values, IP address, and user agent.
6. THE System SHALL expose a `POST /admin/plans/{slug}/deactivate` endpoint that sets `plan_overrides.is_active = false` for the given plan slug.
7. WHEN a plan is deactivated via `plan_overrides.is_active = false`, THE existing subscriptions and user content on that plan SHALL remain unaffected; no cascade deletion or downgrade occurs.
8. WHEN a plan is deactivated, THE `AuditLogger` SHALL record a `plan_deactivated` entry.
9. WHEN a Super Admin updates plan limits, THE `BillingService` SHALL immediately use the new effective limits for all subsequent `canCreateEvent` and `canUploadPhotos` checks; no server restart is required.
10. WHEN an Organizer (non-Super Admin) requests any `/admin/plans/*` route, THE `EnsureSuperAdmin` middleware SHALL return HTTP 403.

---

### Requirement 16: Subscription Management

**User Story:** As a Super Admin, I want to view all subscriptions with filtering, so that I can diagnose billing issues without modifying provider-owned state.

#### Acceptance Criteria

1. THE Subscription List page SHALL be accessible at `GET /admin/subscriptions` and SHALL return a paginated list of all subscriptions (20 per page).
2. THE Subscription List SHALL support filtering by status (active / canceled / expired / past_due / incomplete / trialing), by plan slug (free / pro), and by user (ID or email).
3. EACH record in the Subscription List SHALL include: id, user name/email, plan, provider, status, current_period_start, current_period_end, cancel_at_period_end, created_at.
4. THE Subscription Detail page SHALL be accessible at `GET /admin/subscriptions/{subscription}` and SHALL display all fields listed in criterion 3 plus: canceled_at, updated_at.
5. THE admin subscription endpoints SHALL be read-only; THE System SHALL NOT expose any endpoint to create, update, or cancel subscriptions through the admin UI (provider-owned billing state must not be modified from admin).
6. THE Subscription Detail response SHALL NOT expose: `provider_subscription_id` or any provider secret keys.
7. WHEN a Super Admin requests a subscription that does not exist, THE System SHALL return HTTP 404.

---

### Requirement 17: Payment Management

**User Story:** As a Super Admin, I want to view all payments with filtering, so that I can audit financial activity without accessing sensitive card data.

#### Acceptance Criteria

1. THE Payment List page SHALL be accessible at `GET /admin/payments` and SHALL return a paginated list of all payments (20 per page), sorted by `created_at` descending.
2. THE Payment List SHALL support filtering by status (succeeded / failed / pending), by date range (from/to), and by user (ID or email).
3. EACH record in the Payment List SHALL include: id, user name/email, amount, currency, status, provider, paid_at, created_at.
4. THE Payment Detail page SHALL be accessible at `GET /admin/payments/{payment}` and SHALL display all fields in criterion 3 plus: subscription ID (not provider_subscription_id), subscription plan.
5. THE admin payment endpoints SHALL be read-only; THE System SHALL NOT expose any endpoint to create, update, or refund payments through the admin UI.
6. THE Payment Detail response SHALL NOT expose: `provider_payment_id`, `metadata`, or any card/secret data.
7. WHEN a Super Admin requests a payment that does not exist, THE System SHALL return HTTP 404.

---

### Requirement 18: Audit Log — Storage

**User Story:** As a compliance officer, I want all significant admin actions recorded in an append-only audit log, so that I can trace who did what and when.

#### Acceptance Criteria

1. THE System SHALL maintain an `audit_logs` table with columns: `id` (auto-increment PK), `user_id` (nullable FK → `users.id`, SET NULL on delete), `action` (varchar, e.g. `user_activated`), `target_type` (nullable varchar, e.g. `User`, `Event`, `Photo`, `Plan`), `target_id` (nullable unsignedBigInt), `description` (text), `metadata` (nullable JSON), `ip_address` (nullable varchar), `user_agent` (nullable varchar), `created_at` (timestamp, no `updated_at`).
2. THE `AuditLogger` service SHALL record the following actions: `user_activated`, `user_deactivated`, `role_assigned`, `role_removed`, `event_archived`, `event_deleted`, `photo_deleted`, `plan_limit_updated`, `plan_deactivated`.
3. EACH audit log record SHALL include: the acting Super Admin's user ID (`user_id`), the action string, the target type and ID, a human-readable `description`, the client IP address, and the user agent.
4. THE `audit_logs` table SHALL be append-only in normal usage; THE System SHALL NOT expose any endpoint to update or delete audit log records through the admin UI.
5. THE `metadata` column SHALL store additional structured context (e.g., changed field values for plan limit updates, old and new role for role changes) as JSON; it SHALL NOT contain passwords, secrets, or card data.
6. WHEN the acting admin user is deleted, the `user_id` FK in existing audit log records SHALL be set to NULL (SET NULL on delete constraint), preserving the log record itself.
7. THE `audit_logs` table SHALL have an index on `created_at` and an index on `action` to support efficient filtering.

---

### Requirement 19: Audit Log — UI

**User Story:** As a Super Admin, I want to view and filter the audit log, so that I can review administrative activity.

#### Acceptance Criteria

1. THE Audit Log page SHALL be accessible at `GET /admin/audit-logs` and SHALL return a paginated list of audit log entries (25 per page), sorted by `created_at` descending.
2. THE Audit Log List SHALL support filtering by: action (exact match from a predefined list), actor user ID or email, date range (from/to), and target_type.
3. EACH record displayed in the Audit Log List SHALL include: id, actor name/email (or "System" if `user_id` is NULL), action, target_type, target_id, description, ip_address, created_at.
4. THE Audit Log List response SHALL NOT expose: raw `metadata` JSON in the list view (metadata is available on a detail view or excluded entirely if not needed); no passwords, secrets, or raw storage paths.
5. WHEN a Super Admin requests a specific audit log entry at `GET /admin/audit-logs/{id}`, THE System SHALL display all fields including `metadata` (with sensitive fields stripped) and `user_agent`.
6. WHEN a non-Super Admin requests any audit log endpoint, THE `EnsureSuperAdmin` middleware SHALL return HTTP 403.

---

### Requirement 20: Pagination, Search, and Filtering

**User Story:** As a Super Admin, I want consistent pagination, search, and filtering across all admin list views, so that I can navigate large datasets without performance issues.

#### Acceptance Criteria

1. THE System SHALL apply server-side Pagination to all admin list views: Users (15/page), Events (20/page), Photos (20/page), Subscriptions (20/page), Payments (20/page), Audit Logs (25/page).
2. ALL paginated responses SHALL include pagination metadata (current page, last page, total count, per-page count) passed as Inertia props.
3. THE User List and Event List SHALL support free-text search (case-insensitive partial match against at least two fields per model as specified in Requirements 8 and 12).
4. ALL list views SHALL support the filters described in their respective requirements (8, 12, 14, 16, 17, 19).
5. THE System SHALL NOT load all matching records into memory before paginating; Laravel Eloquent paginate() or similar SHALL be used.
6. WHEN no records match the applied search/filter criteria, THE System SHALL return an empty list with pagination metadata showing zero total records, rather than an error response.
7. Sort by `created_at` descending SHALL be the default order for Payments and Audit Logs. Sort by `id` ascending SHALL be the default for Users and Events.

---

### Requirement 21: Dangerous Action Confirmations

**User Story:** As a Super Admin, I want confirmation dialogs before irreversible actions, so that accidental clicks do not cause unintended data loss or permission changes.

#### Acceptance Criteria

1. THE Frontend SHALL display a confirmation dialog before submitting any of the following requests: user deactivation, role assignment or removal, event deletion, photo deletion, plan deactivation.
2. EACH confirmation dialog SHALL describe the specific consequence of the action (e.g., "This will prevent the user from logging in. This action can be undone by reactivating the account.").
3. THE confirmation dialog pattern SHALL reuse the existing `Dialog` component from the shadcn/ui library already installed in the project; no new dialog library shall be introduced.
4. WHEN a Super Admin dismisses the confirmation dialog without confirming, THE dangerous action SHALL NOT be submitted.
5. THE backend SHALL enforce all authorization rules independently of whether the frontend dialog was shown; the backend SHALL NOT trust that a confirmation was displayed.

---

### Requirement 22: Admin Navigation Visibility

**User Story:** As a Super Admin, I want the admin navigation to appear only for Super Admin users, so that regular organizers are not confused by admin-only links.

#### Acceptance Criteria

1. THE `HandleInertiaRequests::share()` method SHALL be modified to include `auth.user.roles` (an array of role slugs for the authenticated user, e.g. `['super_admin']` or `[]`) in the shared Inertia props.
2. THE `AdminLayout` sidebar navigation SHALL only be rendered when the current user has the `super_admin` role (as determined by `auth.user.roles` from shared props).
3. THE organizer `AppLayout` SHALL NOT render any admin navigation links for any user.
4. THE frontend role check (`auth.user.roles`) SHALL be used only for UI visibility; ALL admin security enforcement SHALL be performed server-side by `EnsureSuperAdmin` middleware and Gate/policy checks.
5. WHEN a non-Super Admin user manually navigates to `/admin` or any `/admin/*` URL, THE server-side `EnsureSuperAdmin` middleware SHALL return HTTP 403 regardless of frontend state.

---

### Requirement 23: Error Handling

**User Story:** As a Super Admin, I want clear, user-friendly error messages in the admin area, so that I can understand what went wrong without exposing system internals.

#### Acceptance Criteria

1. WHEN any admin action results in an error, THE System SHALL return a user-friendly message that describes what went wrong without revealing stack traces, SQL query text, filesystem paths, or internal exception class names.
2. WHEN a validation error occurs on an admin form (e.g., plan limit update with an invalid value), THE System SHALL return HTTP 422 with field-level validation messages formatted consistently with the existing Inertia error-handling pattern.
3. WHEN an unexpected server-side error occurs in any admin controller, THE System SHALL log the full exception server-side (using Laravel's standard logging) and return a generic error message to the frontend.
4. WHEN a Super Admin attempts an action that violates a business rule (e.g., deactivating the last Super Admin), THE System SHALL return HTTP 422 with a descriptive, actionable error message.

---

### Requirement 24: Security

**User Story:** As a security engineer, I want the admin system to prevent IDOR, privilege escalation, CSRF exploits, and secret leakage, so that the system is secure by default.

#### Acceptance Criteria

1. ALL admin controllers SHALL look up resources by their ID (or UUID) and authorize access via Gate/policy checks; no admin endpoint SHALL return a resource that belongs to a different context than the one being requested.
2. ALL state-mutating admin endpoints (POST, PUT, PATCH, DELETE) SHALL be protected by Laravel's CSRF middleware (already applied globally via the web middleware group).
3. NO admin response SHALL include: provider secret keys, payment card data, password hashes, two_factor_secret, two_factor_recovery_codes, raw filesystem storage paths, or any field from `payments.metadata`.
4. WHEN a Super Admin attempts to assign the `super_admin` role to themselves (self-promotion attempt), THE System SHALL reject the request with HTTP 422 (Requirement 10.3 reinforced here for security).
5. WHEN the last `super_admin` role assignment would be removed from the system, THE System SHALL reject the request with HTTP 422 (Requirement 10.4 reinforced here for security).
6. THE `EnsureSuperAdmin` middleware SHALL be the single gate for the `/admin/*` route group; no individual admin controller SHALL implement its own role-check bypass.
7. THE test suite SHALL include privilege-escalation tests verifying that: a guest receives a redirect to login; an Organizer receives HTTP 403; a Super Admin receives HTTP 200; for representative endpoints in each admin section.

---

### Requirement 25: Test Coverage

**User Story:** As a developer, I want comprehensive tests covering all Phase 13 behavior, so that I can confidently deploy and maintain the admin system.

#### Acceptance Criteria

1. THE test suite SHALL include authentication/access tests: guest → redirect to login; Organizer → HTTP 403; Super Admin → HTTP 200; for at least one representative endpoint in each admin section (users, events, photos, plans, subscriptions, payments, audit-logs).
2. THE test suite SHALL include user management tests: list returns paginated results; search by name and email; filter by role and `is_active`; activate and deactivate succeeds; deactivating the last Super Admin returns HTTP 422; role assignment succeeds; self-promotion returns HTTP 422; removing the last Super Admin role returns HTTP 422.
3. THE test suite SHALL include event management tests: admin list returns all events across organizers; organizer's own event list is unaffected (isolation preserved); admin can archive an event; admin can soft-delete an event; organizer cannot archive/delete via admin routes.
4. THE test suite SHALL include photo management tests: admin list returns all photos; admin can delete a photo (record soft-deleted, storage cleaned up); organizer cannot access admin photo routes; deleting a photo creates an audit log entry.
5. THE test suite SHALL include plan management tests: admin can view effective plan limits; admin can update plan limits via `plan_overrides`; `BillingService` uses override values after update; organizer cannot access plan management routes; plan_limit_updated audit log entry is created.
6. THE test suite SHALL include subscription and payment tests: admin list returns all subscriptions; organizer's subscription list is unaffected (own subscriptions only); admin list returns all payments; admin cannot create or modify subscriptions/payments.
7. THE test suite SHALL include audit log tests: each audited admin action (user_activated, user_deactivated, role_assigned, role_removed, event_archived, event_deleted, photo_deleted, plan_limit_updated, plan_deactivated) creates a correctly formed audit log record; unauthorized users cannot access audit log endpoints; no audit log record can be deleted via the admin UI.
8. THE test suite SHALL include IDOR/privilege escalation tests: an Organizer cannot access another organizer's resources via admin routes; a Super Admin's access to resources is correctly authorized through Gate checks.
9. ALL 252 Phase 1–12 tests SHALL continue to pass after Phase 13 implementation; THE test suite SHALL be run via `php artisan test` with no failures.

---

### Requirement 26: Test Factories

**User Story:** As a developer, I want updated and new test factories for all Phase 13 models, so that test data is easy to set up.

#### Acceptance Criteria

1. THE `UserFactory` SHALL be extended with a `superAdmin()` state that creates a user and assigns the `super_admin` role via the `user_roles` pivot.
2. THE `UserFactory` SHALL be extended with an `inactive()` state that sets `is_active = false` on the created user.
3. THE existing `EventFactory`, `PhotoFactory`, `SubscriptionFactory`, and `PaymentFactory` SHALL remain compatible with Phase 13; no breaking changes to their default states.
4. THE System SHALL provide an `AuditLogFactory` that creates `audit_logs` records with sensible defaults, supporting explicit overrides for `action`, `target_type`, `target_id`, `user_id`, and `metadata`.

---

### Requirement 27: Database Integrity

**User Story:** As a database administrator, I want proper foreign keys, indexes, and seed data, so that the database schema is consistent and performant.

#### Acceptance Criteria

1. THE `roles` table SHALL have a unique index on the `name` column.
2. THE `user_roles` table SHALL have a composite primary key on `(user_id, role_id)`, a foreign key on `user_id` (→ `users.id`, cascade delete), and a foreign key on `role_id` (→ `roles.id`, cascade delete).
3. THE `user_roles` table SHALL have an additional index on `user_id` to support efficient `hasRole()` queries.
4. THE `plan_overrides` table SHALL have a unique index on the `slug` column; it SHALL NOT have a foreign key to any other table (plan slugs are config-backed strings, not foreign keys).
5. THE `audit_logs` table SHALL have `user_id` as a nullable FK (→ `users.id`, SET NULL on delete), an index on `created_at`, and an index on `action`.
6. THE `roles` table SHALL be seeded (in a database seeder or migration) with `super_admin` and `organizer` records before any `user_roles` records are inserted.
7. WHEN a user is hard-deleted from the `users` table, THE `user_roles` rows for that user SHALL be cascade-deleted, and THE `audit_logs.user_id` for that user's entries SHALL be set to NULL.

---

### Requirement 28: Documentation

**User Story:** As a developer onboarding to the project, I want documentation explaining the admin system architecture, so that I can understand and maintain it.

#### Acceptance Criteria

1. THE System SHALL include a `docs/admin.md` file documenting: the role system (roles table, user_roles pivot, how to add new roles), the user status mechanism (is_active, login blocking), the `admin:create-admin` Artisan command (usage, behavior), the admin route structure (routes/admin.php, middleware stack), the authorization architecture (AdminAuthorization / policy before() hook, Gate definitions), the AuditLogger service (audited actions, metadata format), and local development testing instructions.
2. THE `docs/admin.md` SHALL be written in Markdown and SHALL include at least one example of each major concept (e.g., example Gate check, example AuditLogger call, example hasRole usage).
3. THE documentation SHALL be accurate to the implemented code; no placeholder or aspirational descriptions.

---

### Requirement 29: Phase 1–12 Regression

**User Story:** As a developer, I want Phase 13 to leave all Phase 1–12 behavior intact, so that existing organizer features continue to work correctly.

#### Acceptance Criteria

1. WHEN `php artisan test` is run after Phase 13 implementation, ALL 252 previously passing tests SHALL continue to pass with no modification to those tests.
2. THE Organizer event management workflow (create, edit, archive, delete events via `/events/*`) SHALL be unaffected by Phase 13 changes.
3. THE billing and subscription workflow (checkout, webhook processing, subscription status, plan limits via `BillingService`) SHALL continue to function correctly, with the only change being that `BillingService` now checks `plan_overrides` first (an additive, backwards-compatible change; if `plan_overrides` is empty, behavior is identical to Phase 12).
4. THE public photo upload and gallery pages (`/e/{uuid}/*`) SHALL remain fully functional for event attendees.
5. THE authentication flows (email/password, passkey, 2FA via Fortify) SHALL remain unchanged except for the addition of the `is_active` check in the authentication pipeline for Phase 13.
6. THE TypeScript compilation (`tsc`) SHALL succeed with no new type errors after Phase 13 changes.
7. THE frontend build (`npm run build`) SHALL succeed after Phase 13 changes.
