# Requirements Document

## Introduction

MomentGather Phase 12 establishes a **SaaS billing foundation** for the existing Laravel 13 + React 19 + Inertia v3 + TypeScript + Tailwind v4 event photo-sharing application. This phase introduces subscription plans (Free/Pro), plan-based usage limits (active events, photos per event, storage), a provider-agnostic payment/checkout/webhook pipeline, and organizer-facing billing UI.

The entire feature runs **locally with automated tests and no real payment credentials**. A `Fake_Payment_Provider` powers both local development and the deterministic test suite. A real provider (PayMongo, intended for a future production phase in the Philippines) is defined only as a documented, swappable interface slot — it is **not implemented** in this phase.

The backend is the **single source of truth** for plan and subscription status. The frontend never decides permissions or limits. Raw card data is never stored, and secret keys are never exposed to the frontend. All Phase 1–11 behavior (auth, dashboard, event CRUD/status, public event page, QR codes, guest uploads, image processing, queue, gallery, downloads, rate limiting, security, isolation) must remain fully intact.

## Glossary

- **Organizer**: An authenticated, email-verified `User` who owns events and manages billing. Uses Fortify auth (with passkeys and 2FA). The `User` model currently has `#[Fillable(['name','email','password'])]` and `hasMany(Event)`; it has no billing fields yet.
- **Plan**: A named tier defining usage limits and (optionally) price. Defined in `config/plans.php`.
- **Free_Plan**: The default plan for every organizer. Limits: `max_active_events=1`, `max_photos_per_event=100`, `max_storage_bytes=500MB`. Price = 0.
- **Pro_Plan**: The paid plan. Limits: `max_active_events=10`, `max_photos_per_event=5000`, `max_storage_bytes=10GB`. Has a price and `billing_interval=monthly`.
- **Plan_Limit**: A single configurable numeric ceiling within a plan (`max_active_events`, `max_photos_per_event`, `max_storage_bytes`).
- **Active_Event**: An `Event` owned by the organizer where `status='active'` AND the row is not soft-deleted. (Event `status` is a `string(20)` default `'active'`; values in use include `active`, `archived`, `draft`; public pages 404 non-active events.)
- **Subscription**: A database record tying an organizer to a plan with a lifecycle status and billing period. The database is the source of truth for who has Pro.
- **Subscription_Status**: An internal, provider-neutral status from the set `{active, past_due, canceled, expired, incomplete, trialing}` (trialing only if the provider supports it).
- **Payment**: A database record of a payment attempt/result (amount, currency, status, reference).
- **Payment_Provider**: An external payment/checkout/subscription system. Selected via `config('billing.provider')`.
- **PaymentProviderInterface**: The abstraction all providers implement (checkout creation, signature/payment verification, subscription create/cancel, webhook processing).
- **Fake_Payment_Provider**: The default provider (`config('billing.provider')='fake'`). Exposes a local fake hosted-checkout page/flow, issues signature-verified and idempotent fake webhooks, and lets tests simulate success/failure/cancel deterministically.
- **Billing_Service**: The authoritative backend service exposing plan/subscription queries and limit checks (`currentPlan`, `isPro`, `hasActiveSubscription`, `canCreateEvent`, `canUploadPhotos`, `storageUsedBytes`, etc.).
- **Checkout_Session**: A provider-created session representing a pending upgrade transaction, with success and cancel return URLs.
- **Webhook**: An HTTP POST from the payment provider to a dedicated public application endpoint reporting an event.
- **Webhook_Signature**: A cryptographic signature on a webhook payload, verified server-side with `PAYMENT_WEBHOOK_SECRET`.
- **Idempotency**: The property that processing the same provider event more than once produces no duplicate records or state changes.
- **Usage**: The organizer's current consumption across three metrics — events (Active_Events count), photos (photos per event), and storage (Storage_Bytes).
- **Storage_Bytes**: The tracked storage metric = live `SUM(photos.file_size)` over the organizer's non-soft-deleted photos across their events. `photos.file_size` is an `unsignedBigInteger` in bytes and stores the ORIGINAL file size. Optimized/thumbnail variants also consume disk but are not separately sized in the database; the tracked metric is the original `file_size` only.
- **Over_Limit**: A state where current Usage for a metric exceeds the current plan's corresponding Plan_Limit (common after a Downgrade).
- **Downgrade**: A transition from Pro_Plan to Free_Plan (via cancellation/expiry). Never deletes existing content.
- **Webhook_Events (processed-events tracking)**: A database table recording processed `provider_event_id` values (UNIQUE) to enforce Idempotency.

## Requirements

### Requirement 1: Plan Structure (config-backed)

**User Story:** As a system operator, I want plans and their limits defined in centralized configuration, so that limits are tunable without editing controllers.

#### Acceptance Criteria

1. THE Billing_Service SHALL read plan definitions from `config/plans.php`, mirroring the existing `config/uploads.php` pattern.
2. THE `config/plans.php` file SHALL define a Free_Plan with `max_active_events=1`, `max_photos_per_event=100`, and `max_storage_bytes` equal to 500 megabytes in bytes.
3. THE `config/plans.php` file SHALL define a Pro_Plan with `max_active_events=10`, `max_photos_per_event=5000`, and `max_storage_bytes` equal to 10 gigabytes in bytes.
4. THE `config/plans.php` file SHALL define a `price` and a `billing_interval` of `monthly` for the Pro_Plan, and a `price` of 0 for the Free_Plan.
5. WHERE a Plan_Limit is configured via an environment variable, THE Billing_Service SHALL use the configured value rather than a hardcoded constant.
6. THE Billing_Service SHALL expose each plan as a value object or structured result so that plan name, limits, price, and billing interval can be read programmatically.
7. IF a requested plan slug is not defined in `config/plans.php`, THEN THE Billing_Service SHALL treat the organizer as being on the Free_Plan.

### Requirement 2: Plans Database Design

**User Story:** As a developer, I want normalized subscription, payment, and webhook-tracking tables, so that the database is the authoritative record of billing state.

#### Acceptance Criteria

1. THE System SHALL provide a `subscriptions` table with columns: `user_id` (FK to users, cascade on delete), `plan` (plan slug/name), `provider`, `provider_subscription_id` (nullable, UNIQUE), `status`, `current_period_start` (nullable), `current_period_end` (nullable), `cancel_at_period_end` (boolean), `canceled_at` (nullable), and timestamps.
2. THE System SHALL provide a `payments` table with columns: `user_id` (FK to users), `subscription_id` (nullable FK to subscriptions), `provider`, `provider_payment_id` (nullable, UNIQUE), `amount`, `currency`, `status`, `paid_at` (nullable), `metadata` (nullable JSON), and timestamps.
3. THE System SHALL provide a `webhook_events` table with at least a `provider_event_id` column that is UNIQUE, plus the event type and timestamps, to track processed webhook events.
4. THE System SHALL define foreign key constraints on `subscriptions.user_id`, `payments.user_id`, and `payments.subscription_id`.
5. THE System SHALL index `subscriptions.user_id` and `payments.user_id`.
6. THE System SHALL NOT store raw card numbers, CVV values, or provider secret keys in any billing table.
7. WHERE a provider identifier is not yet known at record creation, THE System SHALL allow the corresponding `provider_subscription_id` or `provider_payment_id` column to be null.

### Requirement 3: Billing Model

**User Story:** As a product owner, I want a subscription-based billing model, so that Free users pay nothing and Pro users are billed recurring monthly.

#### Acceptance Criteria

1. THE Billing_Service SHALL treat the Free_Plan as requiring no payment and no Subscription record.
2. WHEN an organizer subscribes to the Pro_Plan, THE System SHALL create a Subscription representing a recurring monthly billing arrangement.
3. THE System SHALL isolate all provider-specific billing operations behind the PaymentProviderInterface so that new plans and providers can be added without changing controllers.
4. THE PaymentProviderInterface SHALL define operations for creating a checkout, verifying a payment or webhook signature, creating a subscription, canceling a subscription, and processing a webhook.

### Requirement 4: Payment Provider Integration

**User Story:** As a developer, I want a provider-agnostic payment abstraction with a local fake default, so that I can build and test billing without live credentials.

#### Acceptance Criteria

1. THE System SHALL define a PaymentProviderInterface and resolve the active provider from `config('billing.provider')`.
2. WHERE `config('billing.provider')` equals `fake`, THE System SHALL use the Fake_Payment_Provider as the active provider.
3. THE Fake_Payment_Provider SHALL provide a local fake hosted-checkout page/flow that supports deterministic success, failure, and cancel outcomes.
4. THE Fake_Payment_Provider SHALL issue webhooks that are signed with `PAYMENT_WEBHOOK_SECRET` and are idempotent.
5. THE System SHALL document a real provider adapter (PayMongo) as a swappable interface slot without implementing it in this phase.
6. WHERE the frontend requires a provider key, THE System SHALL expose only a public/publishable key and SHALL NOT expose any secret key.

### Requirement 5: Environment Configuration

**User Story:** As a developer, I want billing environment variables documented, so that setup is reproducible and secrets stay out of the frontend and version control.

#### Acceptance Criteria

1. THE `.env.example` file SHALL include `PAYMENT_PROVIDER`, `PAYMENT_PUBLIC_KEY`, `PAYMENT_SECRET_KEY`, and `PAYMENT_WEBHOOK_SECRET`, alongside the existing `UPLOAD_*` and `BROWSE_*` variables.
2. THE System SHALL NOT send `PAYMENT_SECRET_KEY` or `PAYMENT_WEBHOOK_SECRET` to the React frontend.
3. THE System SHALL NOT commit real payment secret values to version control.
4. WHERE billing-specific tuning is required, THE `.env.example` file SHALL include the corresponding `BILLING_*` or `PLAN_*` variables consumed by `config/plans.php` and `config/billing.php`.

### Requirement 6: Billing Domain Logic (Billing_Service)

**User Story:** As a developer, I want authoritative backend billing queries, so that all permission and limit decisions are made server-side.

#### Acceptance Criteria

1. THE Billing_Service SHALL expose `currentPlan(user)` returning the organizer's effective plan.
2. THE Billing_Service SHALL expose `isPro(user)` and `hasActiveSubscription(user)`.
3. THE Billing_Service SHALL expose `canCreateEvent(user)`, `canUploadPhotos(user, event, incomingCount, incomingBytes)`, and `storageUsedBytes(user)`.
4. THE Billing_Service SHALL compute `storageUsedBytes(user)` as a live `SUM(file_size)` over the organizer's non-soft-deleted photos across their events.
5. WHILE a Subscription has `status=canceled` and `current_period_end` is in the future, THE Billing_Service SHALL report the organizer as Pro.
6. WHEN a Subscription's `current_period_end` has passed and it is not active, THE Billing_Service SHALL report the organizer as Free.
7. THE Billing_Service SHALL be the authoritative decision-maker for all limit checks, independent of any frontend-supplied value.

### Requirement 7: Event Limit Enforcement (EventController@store)

**User Story:** As an organizer, I want event creation to respect my plan's active-event limit, so that upgrades are meaningful without losing access to existing events.

#### Acceptance Criteria

1. WHEN an organizer submits the event-creation form, THE EventController SHALL determine the organizer's plan and count their Active_Events before creating a new event.
2. IF the organizer's Active_Event count is at or above `max_active_events`, THEN THE EventController SHALL reject creation with a friendly upgrade-or-archive message and SHALL NOT create the event.
3. WHILE an organizer is Over_Limit on active events, THE System SHALL allow viewing, editing, and archiving of existing events.
4. WHEN the Active_Event count is below `max_active_events`, THE EventController SHALL create the event with `status='active'` and `upload_enabled=true` as in Phase 11.
5. THE EventController SHALL count only events where `status='active'` and the row is not soft-deleted when evaluating the active-event limit.

### Requirement 8: Photo Limit Enforcement (PublicPhotoUploadController@store)

**User Story:** As a guest uploader, I want clear behavior when an event is at its photo limit, so that uploads succeed or fail as a whole with a clear message.

#### Acceptance Criteria

1. WHEN a guest submits an upload batch, THE PublicPhotoUploadController SHALL determine the event owner's plan, the event's current non-deleted photo count, and the incoming file count before storing any file or dispatching any job.
2. IF the current photo count plus the incoming count would exceed the event owner's `max_photos_per_event`, THEN THE PublicPhotoUploadController SHALL reject the entire batch with HTTP 403 and a friendly upgrade message, storing no files and dispatching no jobs.
3. THE PublicPhotoUploadController SHALL retain the existing abuse-safeguard cap (`config('uploads.max_per_event')`) as a separate atomic gate that coexists with the plan-based photo limit.
4. THE plan-based photo limit SHALL be scoped to the target event only and SHALL NOT be bypassable by splitting or crafting a batch.
5. WHEN neither the plan photo limit nor the abuse cap would be exceeded, THE PublicPhotoUploadController SHALL store originals and dispatch `ProcessPhoto` jobs as in prior phases.

### Requirement 9: Storage Limit Enforcement (upload)

**User Story:** As a system operator, I want uploads to respect the plan storage limit, so that Pro/Free storage ceilings are enforced server-side.

#### Acceptance Criteria

1. WHEN a guest submits an upload batch, THE PublicPhotoUploadController SHALL compute the event owner's `storageUsedBytes` via a live `SUM(file_size)` of the owner's non-soft-deleted photos.
2. THE PublicPhotoUploadController SHALL compute incoming bytes as the sum of the uploaded files' sizes.
3. IF `storageUsedBytes` plus incoming bytes would exceed the owner's `max_storage_bytes`, THEN THE PublicPhotoUploadController SHALL reject the entire batch with HTTP 403 and a friendly upgrade message, storing no files and dispatching no jobs.
4. THE storage limit SHALL be enforced server-side and SHALL be configurable via `config/plans.php`.
5. THE storage limit check SHALL run before any file is stored or any job is dispatched.

### Requirement 10: Downgrade Handling

**User Story:** As an organizer, I want downgrading to preserve my content, so that I never lose events or photos when my subscription ends.

#### Acceptance Criteria

1. WHEN an organizer is downgraded from Pro_Plan to Free_Plan, THE System SHALL NOT delete any existing events or photos.
2. WHILE a downgraded organizer is Over_Limit on active events, THE System SHALL block new event creation while allowing viewing of existing events.
3. WHILE a downgraded organizer is Over_Limit on photos or storage for an event, THE System SHALL block new uploads to that event while allowing viewing of existing photos.
4. WHEN an organizer is Over_Limit, THE System SHALL present a message explaining the restriction and the upgrade or archive options.

### Requirement 11: Subscription Status Mapping

**User Story:** As a developer, I want provider statuses mapped to an internal set, so that the frontend never depends on provider-specific status names.

#### Acceptance Criteria

1. THE System SHALL map provider subscription statuses to the internal set `{active, past_due, canceled, expired, incomplete, trialing}`.
2. THE System SHALL NOT expose provider-specific status names to the frontend.
3. WHILE a Subscription is `canceled` with `current_period_end` in the future, THE Billing_Service SHALL continue to grant Pro until the period ends.
4. WHEN a Subscription is `expired`, THE Billing_Service SHALL revoke Pro.
5. IF a payment is `failed` or a Subscription is `incomplete`, THEN THE Billing_Service SHALL NOT grant Pro.

### Requirement 12: Checkout Flow

**User Story:** As an organizer, I want to upgrade to Pro through a checkout flow, so that I can subscribe without exposing card data to the application.

#### Acceptance Criteria

1. WHEN an organizer chooses to upgrade to Pro from the Billing page, THE System SHALL create a Checkout_Session via the active Payment_Provider.
2. THE Checkout_Session SHALL define success and cancel return URLs handled by the authenticated organizer area.
3. WHERE the active provider is the Fake_Payment_Provider, THE System SHALL present a local fake hosted-checkout page.
4. WHEN the provider confirms a successful checkout, THE System SHALL record the provider-confirmed subscription and payment state in the database.
5. THE System SHALL NOT process raw card data through the Laravel application.

### Requirement 13: Billing Page

**User Story:** As an organizer, I want a billing page, so that I can see my plan, usage, and manage my subscription.

#### Acceptance Criteria

1. THE Billing page SHALL be reachable only within the authenticated, verified organizer area.
2. THE Billing page SHALL display the current plan, Subscription_Status, price, billing interval, plan limits, and current subscription period.
3. THE Billing page SHALL display Usage for events, photos, and storage as used-versus-limit values computed by the backend.
4. WHILE the organizer is on the Free_Plan, THE Billing page SHALL display an upgrade action.
5. WHILE the organizer has an active Subscription, THE Billing page SHALL display a cancel action.
6. THE Billing page SHALL display payment history for the organizer.
7. THE Billing page SHALL reuse the Phase 11 UI style and SHALL be mobile-friendly.

### Requirement 14: Usage Dashboard/Summary

**User Story:** As an organizer, I want a usage summary with progress indicators, so that I understand how close I am to my limits.

#### Acceptance Criteria

1. THE System SHALL compute Usage (active events, total photos, Storage_Bytes) on the backend.
2. THE usage summary SHALL display each metric as used versus limit with a progress bar.
3. WHEN Usage for a metric is well below its limit, THE usage summary SHALL show a normal indicator.
4. WHEN Usage for a metric approaches its limit, THE usage summary SHALL show an approaching indicator.
5. WHEN Usage for a metric reaches or exceeds its limit, THE usage summary SHALL show a reached indicator.

### Requirement 15: Upgrade UX

**User Story:** As an organizer, I want clear upgrade messaging at limits, so that I understand why an action was blocked.

#### Acceptance Criteria

1. WHEN event creation is blocked by the active-event limit, THE System SHALL present a clear upgrade-or-archive message rather than a generic HTTP error.
2. WHEN an upload is blocked by the photo or storage limit, THE System SHALL present a clear upgrade message rather than a generic HTTP error.
3. THE upgrade messaging SHALL include a call to action directing the organizer to the Billing page.

### Requirement 16: Payment Success / Failure / Cancel

**User Story:** As an organizer, I want correct handling of payment outcomes, so that Pro is granted only on confirmed success.

#### Acceptance Criteria

1. WHEN a payment succeeds and is confirmed by the provider, THE System SHALL create or update the Subscription, record a Payment, set the plan to Pro, and show success feedback.
2. IF a payment fails, THEN THE System SHALL keep the prior status, show a clear message, and SHALL NOT activate Pro.
3. WHEN an organizer cancels the checkout, THE System SHALL return to the Billing page safely and SHALL NOT grant Pro.
4. THE database Subscription and Payment state SHALL reflect the provider-confirmed outcome via a verified webhook or verified return.

### Requirement 17: Webhooks

**User Story:** As a developer, I want a secure webhook endpoint, so that provider-confirmed billing state is applied reliably.

#### Acceptance Criteria

1. THE System SHALL expose a dedicated public webhook route that is excluded from CSRF protection and requires no user authentication.
2. WHEN a webhook is received, THE System SHALL verify the Webhook_Signature using `PAYMENT_WEBHOOK_SECRET` before processing.
3. IF the Webhook_Signature is invalid, THEN THE System SHALL reject the webhook with a 4xx response and SHALL NOT change any state.
4. WHEN a valid webhook is processed, THE System SHALL apply the state change within a database transaction.
5. THE System SHALL handle webhook event types for payment succeeded, subscription created, subscription updated, subscription canceled, payment failed, and subscription expired or past_due, limited to those the active provider supports.

### Requirement 18: Webhook Idempotency

**User Story:** As a developer, I want idempotent webhook processing, so that retried or duplicate events cause no duplicate records.

#### Acceptance Criteria

1. THE System SHALL record each processed `provider_event_id` in the `webhook_events` table with a UNIQUE constraint.
2. WHEN a webhook with an already-processed `provider_event_id` is received, THE System SHALL respond 200 without creating duplicate payments, subscriptions, or state changes.
3. THE System SHALL rely on the database UNIQUE constraint on `provider_event_id` to prevent duplicate processing under concurrency.

### Requirement 19: Subscription Cancellation

**User Story:** As an organizer, I want to cancel my subscription with end-of-period access, so that I keep Pro through the period I paid for.

#### Acceptance Criteria

1. WHEN an organizer confirms cancellation, THE System SHALL call the Billing_Service, which SHALL request cancellation from the provider and set local state to `cancel_at_period_end`.
2. THE Billing page SHALL display that the subscription is active until the period-end date with a scheduled-cancellation indicator.
3. WHILE `cancel_at_period_end` is true and `current_period_end` is in the future, THE System SHALL NOT immediately downgrade the organizer.

### Requirement 20: Payment History

**User Story:** As an organizer, I want to see my payment history, so that I can review my billing records.

#### Acceptance Criteria

1. THE System SHALL display payment history entries with date, amount, currency, status, and a reference.
2. THE System SHALL show payment history only to the account owner.
3. THE System SHALL NOT expose sensitive or raw provider data in payment history.

### Requirement 21: Authorization

**User Story:** As an organizer, I want billing scoped to my own account, so that no other user can access my subscription or payments.

#### Acceptance Criteria

1. THE System SHALL restrict billing, subscription, payment, upgrade, and cancel actions to authenticated organizers.
2. THE System SHALL scope subscription, payment, usage, and event data to the requesting organizer only.
3. IF organizer A attempts to access organizer B's billing, subscription, or payment resource, THEN THE System SHALL deny access (prevent IDOR).

### Requirement 22: Security

**User Story:** As a security-conscious operator, I want billing hardened against common attacks, so that secrets, data, and state remain protected.

#### Acceptance Criteria

1. THE System SHALL verify the Webhook_Signature on every webhook before applying any change.
2. THE System SHALL protect billing actions (upgrade, cancel) with standard Inertia CSRF protection while excluding the webhook route from CSRF.
3. THE System SHALL guard `status`, `provider`, `provider_subscription_id`, and `provider_payment_id` against mass assignment from client input.
4. THE System SHALL validate all billing-related input.
5. THE System SHALL NOT send provider secret keys to the frontend or write them to logs.
6. THE System SHALL use Eloquent parameter binding to avoid SQL injection.
7. THE System SHALL NOT log secrets, card data, or tokens, and SHALL NOT expose raw provider errors to users.
8. WHEN a duplicate or replayed webhook is received, THE System SHALL handle it idempotently.

### Requirement 23: Billing Access Checks

**User Story:** As a developer, I want reusable billing checks, so that limit enforcement stays simple and consistent.

#### Acceptance Criteria

1. THE System SHALL centralize billing access checks in the Billing_Service (`canCreateEvent`, `canUploadPhotos`, `hasActiveSubscription`).
2. THE System SHALL avoid introducing excessive middleware for limit enforcement, preferring service-method checks.
3. WHERE a policy is used for billing resource authorization, THE System SHALL keep it minimal and complementary to the Billing_Service.

### Requirement 24: Frontend Integration

**User Story:** As an organizer, I want the React UI to reflect backend billing state, so that displays are accurate while the backend remains authoritative.

#### Acceptance Criteria

1. THE System SHALL pass plan, usage, limits, Subscription_Status, upgrade CTA, payment history, and cancellation state to React via Inertia props.
2. THE frontend SHALL NOT decide any permission or limit; all decisions SHALL be made by the backend.
3. WHEN backend billing state changes, THE System SHALL reflect the updated props on the next Inertia response.

### Requirement 25: Testing

**User Story:** As a developer, I want comprehensive automated tests using the Fake_Payment_Provider, so that billing correctness is verified without real credentials.

#### Acceptance Criteria

1. THE test suite SHALL verify that plans exist and that Plan_Limits are configurable.
2. THE test suite SHALL verify the active-event limit, including that archived events are not counted as active.
3. THE test suite SHALL verify the photo limit, including that a batch cannot bypass it and that event isolation holds.
4. THE test suite SHALL verify the storage limit, including a correct `SUM(file_size)` usage calculation.
5. THE test suite SHALL verify subscription behaviors: active grants Pro, canceled-but-still-in-period grants Pro, expired loses Pro, and failed payment does not grant Pro.
6. THE test suite SHALL verify payment behaviors: success creates a Payment record, failure creates no success record, and a duplicate provider event creates no duplicate.
7. THE test suite SHALL verify webhook behaviors: valid signature accepted, invalid signature rejected, duplicate event idempotent, and supported events update state.
8. THE test suite SHALL verify authorization, including IDOR prevention across organizers.
9. THE test suite SHALL verify that downgrade preserves content and enforces new limits.
10. THE test suite SHALL use the Fake_Payment_Provider with no real credentials, run on in-memory SQLite (never the dev database), and SHALL include factories for Subscription and Payment alongside existing User/Event/Photo factories.
11. THE existing Phase 1–11 tests SHALL continue to pass.

### Requirement 26: Provider Test Mode / Documentation

**User Story:** As a developer, I want documented local billing setup, so that I can run payment tests and simulate outcomes without external services.

#### Acceptance Criteria

1. THE documentation SHALL describe the `PAYMENT_*` environment variables and local setup.
2. THE documentation SHALL describe how to run payment tests and simulate success, failure, and cancel with the Fake_Payment_Provider.
3. THE documentation SHALL describe how to test webhooks locally with the Fake_Payment_Provider without tunneling, noting that a real provider would require a public webhook URL in a future phase.
4. THE documentation SHALL NOT introduce production infrastructure requirements in this phase.

### Requirement 27: Database Integrity

**User Story:** As a developer, I want strong database constraints, so that billing data stays consistent.

#### Acceptance Criteria

1. THE System SHALL enforce UNIQUE constraints on `subscriptions.provider_subscription_id`, `payments.provider_payment_id`, and `webhook_events.provider_event_id`.
2. THE System SHALL define foreign keys on `subscriptions.user_id`, `payments.user_id`, and `payments.subscription_id`.
3. THE System SHALL index `user_id` on billing tables for query performance.
4. WHERE a provider identifier is not yet assigned, THE System SHALL allow the relevant nullable column to hold null while still enforcing uniqueness on non-null values.

### Requirement 28: Regression

**User Story:** As a maintainer, I want all Phase 1–11 functionality intact, so that the billing foundation adds capability without regressions.

#### Acceptance Criteria

1. THE System SHALL preserve auth, dashboard, event CRUD/status, public event page, QR codes, guest uploads, image processing, queue, gallery, downloads, rate limiting, security, and isolation behavior from Phases 1–11.
2. THE full `php artisan test` suite SHALL pass.
3. THE TypeScript type check (`tsc`) SHALL pass.
4. THE frontend build SHALL succeed.

### Requirement 29: UI/UX Consistency

**User Story:** As an organizer, I want billing UI consistent with the rest of the app, so that the experience feels cohesive.

#### Acceptance Criteria

1. THE billing UI SHALL present plan cards, pricing, limits, and usage bars consistent with the Phase 11 style.
2. THE billing UI SHALL provide an upgrade action and display Subscription_Status.
3. WHEN an organizer initiates cancellation, THE billing UI SHALL show a confirmation dialog.
4. WHEN a billing action succeeds or fails, THE System SHALL show feedback via the existing Inertia toast infrastructure (`Inertia::flash('toast', {type, message})` → `useFlashToast` → sonner).
5. THE billing UI SHALL be mobile-friendly.

## Out of Scope

- Real provider adapter implementation (only the PaymentProviderInterface, Fake_Payment_Provider, and documentation are delivered now; PayMongo is a documented future slot).
- Production deployment and webhook tunneling.
- Redis, Horizon, S3, CDN, cloud, or any deployment infrastructure.
- AI features.
- Storing card data of any kind.
- Multi-currency beyond a single configurable currency.
- Proration and complex billing math.
- Team, multi-seat, or organization billing.
- Coupons, discounts, and taxes.

## Decisions Summary

| ID | Decision | Encoded In |
|----|----------|-----------|
| A | Provider-agnostic billing behind `PaymentProviderInterface`; default `Fake_Payment_Provider` powers local dev and the deterministic test suite (fake hosted checkout, signed + idempotent webhooks, simulate success/failure/cancel). Real adapter (PayMongo) is a documented, swappable slot — interface + config seam + docs only, not implemented. | Req 3, 4, 12, 17, 26 |
| B | Plans are config-backed in `config/plans.php` (env-tunable, mirroring `config/uploads.php`). Free (1 event / 100 photos / 500MB) and Pro (10 / 5000 / 10GB, monthly price). Subscriptions and Payments are DB tables; DB is the source of truth for who has Pro. | Req 1, 2, 6 |
| C | Storage usage = live `SUM(photos.file_size)` (bytes) over the owner's non-deleted photos across events. No counter column, no schema drift, no filesystem scan. Tracked metric is the original `file_size` only. | Req 6, 9, 14 |
| D | Over-limit batch behavior = ATOMIC REJECT. If an upload batch would exceed the owner's per-event photo limit OR storage limit, reject the whole batch (HTTP 403 + friendly upgrade message), storing nothing and dispatching no jobs. Supersedes the brief's partial-accept example. | Req 8, 9 |

## New vs Existing

**Net-new artifacts (this phase):**
- `config/plans.php` and `config/billing.php` (plan limits, price/interval, provider selection).
- `subscriptions`, `payments`, and `webhook_events` migrations + models + factories.
- `Billing_Service` (plan/subscription queries and limit checks).
- `PaymentProviderInterface` + `Fake_Payment_Provider` + provider resolution/config seam.
- Billing controllers/routes (billing page, upgrade/checkout, cancel, checkout return, public webhook endpoint).
- Billing/Usage React pages and components (Inertia).
- `PAYMENT_*` (and any `BILLING_*`/`PLAN_*`) entries in `.env.example`.
- Local billing documentation.

**Extensions to existing code:**
- `EventController@store`: add an active-event-limit gate before creation (Req 7). Existing transaction, slug generation, `status='active'`, `upload_enabled=true`, and toast flash preserved.
- `PublicPhotoUploadController@store`: add plan-based photo-limit and storage-limit atomic gates before storing/dispatching (Req 8, 9). Existing abuse-safeguard cap (`config('uploads.max_per_event')`) retained as a separate coexisting gate; existing store/`ProcessPhoto::dispatch` loop preserved.
- `User` model: add `subscriptions()`/`payments()` relationships. Billing status columns are NOT added to `users`; the DB source of truth is the `subscriptions` table.
- `bootstrap/app.php`: exclude the webhook route from CSRF via the Laravel 13 middleware configuration (mechanism finalized in design).
- Reuse existing Inertia toast infrastructure for billing feedback.
