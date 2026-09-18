# Design Document

## Overview

MomentGather Phase 12 adds a **SaaS billing foundation** to the existing Laravel 13 + React 19 + Inertia v3 + TypeScript + Tailwind v4 application. It introduces Free/Pro subscription plans, plan-based usage limits (active events, photos per event, storage), a provider-agnostic checkout/webhook pipeline, and an organizer-facing billing UI.

Foundation goals, each traced to requirements:

- **Provider-agnostic with a Fake default** — All provider-specific behavior sits behind `PaymentProviderInterface`; the default `FakePaymentProvider` (`config('billing.provider') = 'fake'`) powers local development and the entire automated test suite with no real credentials. A real adapter (PayMongo) is a documented, swappable slot, not implemented this phase. _(R3, R4, R12, R17, R26; Decision A)_
- **Config-backed plans** — Plans and their limits live in `config/plans.php`, mirroring the existing `config/uploads.php` pattern, and are env-tunable. _(R1, R5; Decision B)_
- **Database is the source of truth** — `subscriptions`, `payments`, and `webhook_events` tables are authoritative for who has Pro. No billing columns are added to `users`. _(R2, R6; Decision B)_
- **Backend authoritative** — All permission and limit decisions are made by `BillingService` server-side; the frontend never decides permissions. _(R6, R23, R24)_
- **Atomic reject** — An upload batch that would exceed the owner's per-event photo limit or storage limit is rejected as a whole (HTTP 403 + friendly upgrade message), storing nothing and dispatching no jobs. _(R8, R9; Decision D)_
- **Storage via live SUM** — Storage usage is `SUM(photos.file_size)` (original bytes) over the owner's non-soft-deleted photos across their events. No counter column, no schema drift. _(R6.4, R9, R14; Decision C)_
- **Preserve Phases 1–11** — Auth, dashboard, event CRUD/status, public event page, QR, guest uploads, image processing, queue, gallery, downloads, rate limiting, security, and isolation remain fully intact. _(R28)_
- **Local-only, secrets protected** — No Redis/Horizon/S3/cloud/AI/deployment. Raw card data is never stored; secret keys and the webhook secret are never exposed to the frontend or logs. _(R4.6, R5, R22)_

## Architecture

The feature is organized into five layers. Provider-specific code is confined to the provider adapter; everything above it depends only on `PaymentProviderInterface` and `BillingService`.

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Frontend (Inertia / React / TS)                                          │
│  pages/settings/billing.tsx · pages/Billing/FakeCheckout.tsx             │
│  components: PlanCard · UsageMeter · PaymentHistoryList · CancelDialog    │
│  settings nav (+ Billing entry) · optional dashboard usage widget        │
└───────────────▲───────────────────────────────────────┬──────────────────┘
                │ Inertia props (plan, usage, status,     │ POST checkout/cancel
                │ limits, history, publicKey)             ▼
┌───────────────┴───────────────────────────────────────────────────────────┐
│ HTTP layer (controllers)                                                   │
│  Billing\BillingController (show)                                          │
│  Billing\CheckoutController (store, success, cancel)                       │
│  Billing\CancelSubscriptionController (store)                              │
│  Billing\WebhookController (public, CSRF-excluded)                         │
│  EventController@store (+event-limit gate)                                 │
│  PublicPhotoUploadController@store (+photo/storage atomic gate)            │
└───────────────▲───────────────────────────────────────┬──────────────────┘
                │ queries + limit checks                  │ provider ops
                ▼                                         ▼
┌───────────────────────────────┐        ┌────────────────────────────────┐
│ Domain layer                  │        │ Provider seam                   │
│  Billing\BillingService       │        │  Contracts\PaymentProvider-     │
│  Billing\Plan (value object)  │        │    Interface                    │
│  DTOs: CheckoutSession,        │◀──────▶│  Providers\FakePaymentProvider  │
│    NormalizedWebhookEvent     │  bound  │  (default; HMAC-signed webhooks)│
│                               │  via    │  [future: PayMongoProvider]     │
└───────────────▲───────────────┘ config  └────────────────────────────────┘
                │ Eloquent
                ▼
┌───────────────────────────────────────────────────────────────────────────┐
│ Data layer                                                                 │
│  subscriptions · payments · webhook_events                                 │
│  Models: Subscription · Payment · WebhookEvent (+ User relations)          │
└───────────────────────────────────────────────────────────────────────────┘
┌───────────────────────────────────────────────────────────────────────────┐
│ Config layer                                                               │
│  config/plans.php (Free/Pro limits, price, interval; PLAN_*/BILLING_* env) │
│  config/billing.php (provider driver, keys via env, currency)              │
└───────────────────────────────────────────────────────────────────────────┘
```

### Config layer

- **`config/plans.php`** — Free and Pro plan definitions: `slug`, `name`, `price`, `billing_interval`, `max_active_events`, `max_photos_per_event`, `max_storage_bytes`. Values are env-tunable via `PLAN_*` (e.g. `PLAN_FREE_MAX_ACTIVE_EVENTS`, `PLAN_PRO_MAX_STORAGE_BYTES`). Mirrors `config/uploads.php`. _(R1, R5.4)_
- **`config/billing.php`** — `provider` (from `PAYMENT_PROVIDER`, default `fake`), `public_key` (`PAYMENT_PUBLIC_KEY`), `secret_key` (`PAYMENT_SECRET_KEY`), `webhook_secret` (`PAYMENT_WEBHOOK_SECRET`), `currency` (`BILLING_CURRENCY`, default `PHP`). Secret and webhook_secret are read only server-side. _(R4.1, R5)_

### Domain layer

- **`App\Billing\BillingService`** — The single authoritative source for plan/subscription queries and all limit checks. _(R6, R23)_
- **`App\Billing\Plan`** — A readonly value object built from config (`Plan::fromConfig($slug)`), exposing name/limits/price/interval programmatically. _(R1.6)_
- **`App\Billing\Contracts\PaymentProviderInterface`** — The abstraction implemented by every provider. _(R3.4, R4.1)_
- **`App\Billing\Providers\FakePaymentProvider`** — The default adapter. Deterministic; signs webhooks with HMAC using `PAYMENT_WEBHOOK_SECRET`; `createCheckoutSession` returns a URL to the local fake checkout route carrying a signed token. _(R4.2–4.4, R12.3)_
- **DTOs** — `App\Billing\DTO\CheckoutSession` and `App\Billing\DTO\NormalizedWebhookEvent` as simple readonly classes.

Provider method contract (conceptual):

| Method | Returns | Purpose |
|--------|---------|---------|
| `createCheckoutSession(User $user, string $planSlug)` | `CheckoutSession{url, providerRef}` | Begin an upgrade. |
| `verifyWebhookSignature(string $payload, ?string $signatureHeader, string $secret)` | `bool` | Authenticate an inbound webhook. |
| `parseWebhook(string $payload)` | `NormalizedWebhookEvent{providerEventId, type, data}` | Normalize provider payloads to internal shape. |
| `cancelSubscription(Subscription $subscription)` | `void` | Request end-of-period cancellation at the provider. |

The active provider is resolved from `config('billing.provider')` via a container binding (see `BillingServiceProvider` below), so controllers depend only on the interface. _(R3.3, R4.1)_

### Data layer

Three tables (`subscriptions`, `payments`, `webhook_events`) and their models. Full schema in [Data Models](#data-models). `Plan` is config-derived — there is **no** `plans` table. _(R2, R27)_

### HTTP layer

- `Billing\BillingController@show` — renders the Billing page.
- `Billing\CheckoutController@store` / `@success` / `@cancel` — starts checkout and handles verified returns.
- `Billing\CancelSubscriptionController@store` — cancels at period end.
- `Billing\WebhookController@handle` — public, CSRF-excluded, signature-verified, idempotent, transactional.
- Extensions to `EventController@store` and `PublicPhotoUploadController@store` (limit gates).

### Frontend

Inertia page `settings/billing` (plan cards, status, usage bars, payment history, upgrade/cancel), plus a dev/test `Billing/FakeCheckout` page. Components: `PlanCard`, `UsageMeter`, `PaymentHistoryList`, `CancelSubscriptionDialog`. Reuses the Phase 11 toast infra and settings layout nav. _(R13, R14, R20, R24, R29)_

### Request flows

**(a) Upgrade / checkout** _(R12, R16)_

```
Billing page ──POST billing/checkout──▶ CheckoutController@store
  └─ provider.createCheckoutSession(user, 'pro') ⇒ CheckoutSession{url}
  └─ redirect(url)  ──▶ Billing/FakeCheckout page (fake hosted checkout)
       user chooses Success | Fail | Cancel
         Success ─▶ provider emits signed webhook ─▶ WebhookController (authoritative)
                    and redirect to billing/checkout/success (verified return, convenience)
         Cancel  ─▶ redirect to billing/checkout/cancel ─▶ Billing page (no Pro)
         Fail    ─▶ webhook payment_failed + return ─▶ Billing page (no Pro)
```

State is applied by the **webhook** (authoritative). The verified return is a UX convenience; idempotency (below) ensures no double-apply if both fire.

**(b) Webhook** _(R17, R18, R22)_

```
provider POST billing/webhook
  └─ verifyWebhookSignature(payload, header, secret)  ──invalid──▶ 4xx, no state change
  └─ parseWebhook ⇒ {providerEventId, type, data}
  └─ INSERT webhook_events(provider_event_id) ──duplicate (unique)──▶ 200 no-op
  └─ DB::transaction: upsert subscription + payment per event type
  └─ 200
```

**(c) Limit checks** _(R7, R8, R9, R10)_

```
EventController@store          ─▶ BillingService::canCreateEvent(user)      ─false─▶ friendly reject (no create)
PublicPhotoUploadController@store ─▶ BillingService::canUploadPhotos(owner, event, count, bytes)
                                                                             ─false─▶ abort(403) (store/dispatch nothing)
```

## Components and Interfaces

### New backend files

#### `config/plans.php` _(R1, R5.4)_
Returns an array keyed by plan slug. Shape:
```php
return [
    'free' => [
        'slug' => 'free',
        'name' => 'Free',
        'price' => 0,
        'billing_interval' => null,
        'max_active_events'    => (int) env('PLAN_FREE_MAX_ACTIVE_EVENTS', 1),
        'max_photos_per_event' => (int) env('PLAN_FREE_MAX_PHOTOS_PER_EVENT', 100),
        'max_storage_bytes'    => (int) env('PLAN_FREE_MAX_STORAGE_BYTES', 500 * 1024 * 1024), // 500MB
    ],
    'pro' => [
        'slug' => 'pro',
        'name' => 'Pro',
        'price' => (int) env('PLAN_PRO_PRICE', 49900), // minor units
        'billing_interval' => env('PLAN_PRO_BILLING_INTERVAL', 'monthly'),
        'max_active_events'    => (int) env('PLAN_PRO_MAX_ACTIVE_EVENTS', 10),
        'max_photos_per_event' => (int) env('PLAN_PRO_MAX_PHOTOS_PER_EVENT', 5000),
        'max_storage_bytes'    => (int) env('PLAN_PRO_MAX_STORAGE_BYTES', 10 * 1024 * 1024 * 1024), // 10GB
    ],
];
```

#### `config/billing.php` _(R4.1, R5)_
```php
return [
    'provider'       => env('PAYMENT_PROVIDER', 'fake'),
    'currency'       => env('BILLING_CURRENCY', 'PHP'),
    'public_key'     => env('PAYMENT_PUBLIC_KEY'),
    'secret_key'     => env('PAYMENT_SECRET_KEY'),      // server-only
    'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),  // server-only
];
```

#### `database/migrations/*_create_subscriptions_table.php` _(R2.1, R2.4, R2.5, R27)_
Columns and constraints listed in [Data Models](#data-models).

#### `database/migrations/*_create_payments_table.php` _(R2.2, R2.4, R2.5, R27)_
As per Data Models.

#### `database/migrations/*_create_webhook_events_table.php` _(R2.3, R18, R27)_
As per Data Models.

#### `app/Models/Subscription.php` _(R2, R6.5, R11, R19, R22.3)_
- `belongsTo(User)`.
- **Mass-assignment guard:** `status`, `provider`, `provider_subscription_id` are guarded (use `$fillable` allowing only server-safe attrs, or `$guarded` covering these). Client input never sets these.
- Casts: `current_period_start`, `current_period_end`, `canceled_at` → `datetime`; `cancel_at_period_end` → `bool`.
- Constants for `Subscription_Status` set `{active, past_due, canceled, expired, incomplete, trialing}`.
- Scopes: `scopeActive()` (status active), `scopeOnPlan($slug)`.
- Helpers: `isActiveNow()` (status active, or canceled/`cancel_at_period_end` with `current_period_end` in the future), `onGracePeriod()` (canceled but period-end in future).

#### `app/Models/Payment.php` _(R2.2, R20, R22.3)_
- `belongsTo(User)`, `belongsTo(Subscription)`.
- Guard `provider`, `provider_payment_id`, `status` from mass assignment.
- Casts: `amount` → `int` (minor units), `paid_at` → `datetime`, `metadata` → `array`.

#### `app/Models/WebhookEvent.php` _(R18)_
- Fields: `provider_event_id` (unique), `type`, timestamps. Minimal; used as the idempotency ledger.

#### `app/Billing/Plan.php` _(R1.6)_
Readonly value object:
```php
final readonly class Plan {
    public function __construct(
        public string $slug, public string $name, public int $price,
        public ?string $billingInterval, public int $maxActiveEvents,
        public int $maxPhotosPerEvent, public int $maxStorageBytes,
    ) {}
    public static function fromConfig(string $slug): self; // falls back to 'free' if slug undefined (R1.7)
}
```

#### `app/Billing/BillingService.php` _(R6, R7, R8, R9, R11, R23)_
Authoritative API:
| Method | Behavior |
|--------|----------|
| `currentPlan(User): Plan` | Pro if `isPro`, else Free (via `Plan::fromConfig`). _(R6.1, R1.7)_ |
| `isPro(User): bool` | True iff an active subscription, or canceled with `current_period_end` in the future. False for expired/incomplete/failed. _(R6.2, R6.5, R6.6, R11.3–11.5)_ |
| `hasActiveSubscription(User): bool` | True iff a non-expired, non-incomplete subscription grants access now. _(R6.2)_ |
| `canCreateEvent(User): bool` | `activeEventCount(user) < currentPlan.maxActiveEvents`. _(R6.3, R7)_ |
| `canUploadPhotos(User $owner, Event $event, int $incomingCount, int $incomingBytes): bool` | True iff `event photos + incomingCount ≤ maxPhotosPerEvent` AND `storageUsedBytes(owner) + incomingBytes ≤ maxStorageBytes`. _(R6.3, R8, R9)_ |
| `storageUsedBytes(User): int` | `SUM(photos.file_size)` joined via the owner's events, non-soft-deleted photos only. _(R6.4, R9.1)_ |
| `usageSummary(User): array` | `{events:{used,limit}, photos:{used,limit}, storage:{used,limit}}` computed backend-side; photos used = total non-deleted photos across events (limit is per-event, surfaced per plan). _(R14.1)_ |

`activeEventCount` counts `status='active'` and not soft-deleted only. _(R7.5)_

#### `app/Billing/Contracts/PaymentProviderInterface.php` _(R3.4, R4.1)_
Declares `createCheckoutSession`, `verifyWebhookSignature`, `parseWebhook`, `cancelSubscription` (signatures per the Architecture table).

#### `app/Billing/Providers/FakePaymentProvider.php` _(R4.2–4.4, R12.3)_
- `createCheckoutSession` returns a `CheckoutSession` whose `url` points to the local `billing.fake-checkout` route, carrying a signed token (user id + plan + nonce, HMAC with webhook secret).
- `verifyWebhookSignature` recomputes HMAC-SHA256 over the raw body with `PAYMENT_WEBHOOK_SECRET` and compares with `hash_equals`.
- `parseWebhook` decodes the JSON body into a `NormalizedWebhookEvent`.
- Provides a test helper to build a signed webhook payload (used by the fake checkout page and by tests).

#### `app/Billing/DTO/CheckoutSession.php`, `app/Billing/DTO/NormalizedWebhookEvent.php`
Readonly value carriers (`url`, `providerRef`; `providerEventId`, `type`, `data`).

#### `app/Http/Controllers/Billing/BillingController.php` _(R13, R20, R24)_
`show(Request)` → `Inertia::render('settings/billing', [...])` with: current plan, subscription status, price, interval, plan limits, current period, `usageSummary`, payment history (owner-scoped), `publicKey` (`config('billing.public_key')` only — never a secret). All values via `BillingService`, scoped to `$request->user()`.

#### `app/Http/Controllers/Billing/CheckoutController.php` _(R12, R16)_
- `store(Request)` → `provider->createCheckoutSession($request->user(), 'pro')`, redirect to session URL.
- `success(Request)` → verified return; flash success toast, redirect to Billing. State authority remains the webhook.
- `cancel(Request)` → flash neutral toast, redirect to Billing; no Pro granted.

#### `app/Http/Controllers/Billing/CancelSubscriptionController.php` _(R19)_
`store(Request)` → resolve the user's active subscription, call `provider->cancelSubscription`, set local `cancel_at_period_end=true`; flash toast. Scoped to `$request->user()`.

#### `app/Http/Controllers/Billing/WebhookController.php` _(R17, R18, R22)_
`handle(Request)`:
1. `verifyWebhookSignature(raw body, header, secret)` — invalid → `abort(400)`, no state change.
2. `parseWebhook` → normalized event.
3. Insert `webhook_events.provider_event_id`; on unique violation → return `200` (idempotent no-op).
4. `DB::transaction`: upsert subscription and/or payment by `type` (payment_succeeded, subscription_created/updated/canceled, payment_failed, subscription_expired/past_due).
5. Return `200`.

#### `app/Http/Requests/Billing/*` (FormRequests)
Validation for checkout/cancel inputs (e.g. plan slug allowlist). Keeps input validation out of controllers. _(R22.4)_

#### `app/Providers/BillingServiceProvider.php` (or binding in `AppServiceProvider`) _(R3.3, R4.1)_
Binds `PaymentProviderInterface` to the concrete provider chosen by `config('billing.provider')` (`fake` → `FakePaymentProvider`). Registered in `bootstrap/providers.php` if a dedicated provider is used.

### Modified backend files

#### `app/Models/User.php` _(R2, R21.2)_
Add `subscriptions(): HasMany` and `payments(): HasMany`. **No billing state columns** added to `users`. `#[Fillable(['name','email','password'])]` unchanged.

#### `app/Http/Controllers/EventController.php` (`@store`) _(R7, R15.1)_
Inject/resolve `BillingService`. Inside the existing `DB::transaction`, **before** creating: if `! $billing->canCreateEvent($request->user())` → flash a friendly upgrade-or-archive toast (`Inertia::flash('toast', ['type'=>'error', ...])`) and `return back()` without creating. Otherwise the existing create path (`status='active'`, `upload_enabled=true`, success toast, `to_route('events.show')`) is unchanged. Viewing/editing/archiving unaffected. _(R7.3)_

#### `app/Http/Controllers/PublicPhotoUploadController.php` (`@store`) _(R8, R9, R10.3, R15.2)_
After resolving the active event and the `upload_enabled` check, and coexisting with the existing abuse cap (`config('uploads.max_per_event')`):
- Resolve the **event owner** (`$event->user`) and `BillingService`.
- `$incomingCount = count($request->file('photos'))`; `$incomingBytes = array_sum(array_map(fn($f) => $f->getSize(), $request->file('photos')))`.
- If `! $billing->canUploadPhotos($owner, $event, $incomingCount, $incomingBytes)` → `abort(403, <friendly upgrade message>)`, storing nothing and dispatching nothing.
- Otherwise the existing store + `ProcessPhoto::dispatch` loop runs unchanged. The plan gate and the abuse cap are separate atomic gates. Event isolation preserved. _(R8.3, R8.4)_

#### `routes/web.php` _(R12, R13.1, R17.1, R19, R21.1)_
Under `['auth','verified']`:
- `GET billing` → `BillingController@show` (`billing.show`)
- `POST billing/checkout` → `CheckoutController@store` (`billing.checkout`)
- `GET billing/checkout/success` → `CheckoutController@success` (`billing.checkout.success`)
- `GET billing/checkout/cancel` → `CheckoutController@cancel` (`billing.checkout.cancel`)
- `POST billing/cancel` → `CancelSubscriptionController@store` (`billing.cancel`)
- `GET billing/fake-checkout` → fake hosted checkout page (dev/test surface, still auth) (`billing.fake-checkout`)

Public (no auth), CSRF-excluded:
- `POST billing/webhook` → `WebhookController@handle` (`billing.webhook`)

#### `bootstrap/app.php` _(R17.1, R22.2)_
Inside `withMiddleware`, add `$middleware->validateCsrfTokens(except: ['billing/webhook']);` (Laravel 12/13 API). Existing `web(append: [...])` and `encryptCookies` unchanged.

#### `app/Http/Middleware/HandleInertiaRequests.php` (optional) _(R24.1)_
Optionally add a minimal shared `billing` prop `{ plan: slug, isPro: bool }` for nav badges, computed via `BillingService`. Keeps shared props minimal; the Billing page gets full usage from its controller. **Never** shares secret keys.

#### `.env.example` _(R5.1, R5.4)_
Add alongside `UPLOAD_*`/`BROWSE_*`: `PAYMENT_PROVIDER=fake`, `PAYMENT_PUBLIC_KEY=`, `PAYMENT_SECRET_KEY=`, `PAYMENT_WEBHOOK_SECRET=`, `BILLING_CURRENCY=PHP`, and the `PLAN_*` tuning vars.

### New frontend files

#### `resources/js/pages/settings/billing.tsx` _(R13, R14, R20, R24, R29)_
Uses the settings layout. Renders plan cards, current status/period, usage meters (events/photos/storage), payment history, upgrade action (Free) or cancel action (active sub). Reuses Phase 11 primitives, `EmptyState`, and the toast infra. Mobile-first. Props typed via `BillingPageProps`.

#### `resources/js/pages/Billing/FakeCheckout.tsx` _(R4.3, R12.3)_
Clearly-labelled dev/test hosted-checkout surface with **Success / Fail / Cancel** buttons that post back to the appropriate return route (and, for the fake provider, trigger the signed webhook). Not shown for real providers.

#### Components _(R14, R19.2, R29)_
- `PlanCard` — plan name, price, limits, current-plan/CTA state.
- `UsageMeter` — progress bar with `normal | approaching | reached` states (thresholds computed from backend used/limit). _(R14.3–14.5)_
- `PaymentHistoryList` (or table) — date, amount, currency, status, reference; owner-only data. _(R20)_
- `CancelSubscriptionDialog` — confirmation dialog before cancel. _(R29.3)_

#### Types _(R24.1)_
`resources/js/types/billing.ts` — `Plan`, `Subscription`, `Payment`, `BillingUsage`, `BillingPageProps`.

### Modified frontend files

- `resources/js/layouts/settings/layout.tsx` — add a **Billing** entry to `sidebarNavItems` (mirrors Profile/Security/Appearance). _(R13.1, R29.2)_
- `resources/js/pages/dashboard.tsx` (optional) — small usage widget linking to Billing, using backend-computed usage. _(R14)_

## Data Models

No billing columns are added to `users`. `Plan` is config-derived (no `plans` table). Storage metric = `SUM(photos.file_size)` (original bytes) over the owner's non-soft-deleted photos.

### `subscriptions` _(R2.1, R2.4, R2.5, R27)_
| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | no | |
| `user_id` | bigint FK→users | no | `cascadeOnDelete`; indexed |
| `plan` | string(50) | no | plan slug |
| `provider` | string(50) | no | e.g. `fake` |
| `provider_subscription_id` | string(191) | yes | **UNIQUE** (nulls allowed, unique on non-null) |
| `status` | string(20) | no | internal Subscription_Status |
| `current_period_start` | timestamp | yes | |
| `current_period_end` | timestamp | yes | grace-period comparisons |
| `cancel_at_period_end` | boolean | no | default `false` |
| `canceled_at` | timestamp | yes | |
| `created_at`/`updated_at` | timestamps | no | |

Index: `user_id`. Unique: `provider_subscription_id`.

### `payments` _(R2.2, R2.4, R2.5, R27)_
| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | no | |
| `user_id` | bigint FK→users | no | indexed |
| `subscription_id` | bigint FK→subscriptions | yes | `nullOnDelete` |
| `provider` | string(50) | no | |
| `provider_payment_id` | string(191) | yes | **UNIQUE** (nulls allowed) |
| `amount` | integer | no | minor units |
| `currency` | string(3) | no | e.g. `PHP` |
| `status` | string(20) | no | `succeeded`/`failed`/... |
| `paid_at` | timestamp | yes | |
| `metadata` | json | yes | non-sensitive only |
| `created_at`/`updated_at` | timestamps | no | |

Index: `user_id`. Unique: `provider_payment_id`.

### `webhook_events` _(R2.3, R18, R27)_
| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | no | |
| `provider_event_id` | string(191) | no | **UNIQUE** — idempotency key |
| `type` | string(100) | no | normalized event type |
| `created_at`/`updated_at` | timestamps | no | |

### Subscription_Status (internal enum/constants)
`{active, past_due, canceled, expired, incomplete, trialing}`. Never exposed raw to the frontend. _(R11.1, R11.2)_

### Provider status → internal mapping (illustrative)
| Provider status (conceptual) | Internal |
|------------------------------|----------|
| `active` / `paid` | `active` |
| `past_due` / `unpaid` | `past_due` |
| `canceled` (period not ended) | `canceled` (+`cancel_at_period_end`) |
| `expired` / `ended` | `expired` |
| `incomplete` / `requires_payment` | `incomplete` |
| `trialing` | `trialing` (only if provider supports) |

The FakePaymentProvider emits internal-shaped statuses directly; a real adapter maps its own vocabulary here. _(R11.1)_

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Event-limit soundness
*For any* user on plan `P` with `A` active events, `EventController@store` creates a new event **iff** `A < P.max_active_events`. Archived and soft-deleted events are never counted toward `A`.
**Validates: Requirements 7.1, 7.2, 7.4, 7.5**

### Property 2: Photo-limit atomicity
*For any* upload batch of `N` files to an event whose owner has `C` non-deleted photos and per-event limit `M`, the batch is accepted (all `N` stored **and** `N` `ProcessPhoto` jobs dispatched) **iff** `C + N ≤ M`; otherwise the response is 403 with **zero** files stored and **zero** jobs dispatched.
**Validates: Requirements 8.1, 8.2, 8.4, 8.5**

### Property 3: Storage-limit atomicity
*For any* upload batch summing to `B` bytes to an event whose owner has `storageUsedBytes = S` and storage limit `L`, the batch is accepted **iff** `S + B ≤ L`; otherwise 403 with zero stored and zero dispatched. `S = SUM(file_size)` over the owner's non-soft-deleted photos.
**Validates: Requirements 9.1, 9.2, 9.3, 9.5**

### Property 4: Pro-access correctness
*For any* user, `isPro(user)` is true **iff** they have a subscription with `status=active`, or a `canceled` subscription with `current_period_end` in the future; it is false for `expired`, `incomplete`, and users whose only payments failed.
**Validates: Requirements 6.2, 6.5, 6.6, 11.3, 11.4, 11.5**

### Property 5: Webhook idempotency
*For any* webhook with `provider_event_id = E`, processing it two or more times produces exactly one subscription/payment effect — no duplicate rows and no repeated state transition.
**Validates: Requirements 18.1, 18.2, 18.3, 22.8**

### Property 6: Webhook signature integrity
*For any* inbound webhook payload, if the signature does not verify against `PAYMENT_WEBHOOK_SECRET`, the system responds 4xx and makes **no** state change (no subscription, payment, or webhook_events row).
**Validates: Requirements 17.2, 17.3, 22.1**

### Property 7: Downgrade content preservation
*For any* user downgraded from Pro to Free, no events or photos are deleted; being over-limit blocks new event creation and new uploads but never blocks viewing existing events or photos.
**Validates: Requirements 10.1, 10.2, 10.3, 7.3**

### Property 8: Authorization / IDOR safety
*For any* two distinct users A and B, all billing, subscription, payment, and usage queries executed for A are scoped to A's `user_id`; A can never read or mutate B's billing resources.
**Validates: Requirements 21.1, 21.2, 21.3**

### Property 9: Downgrade limit enforcement
*For any* over-limit user, `canCreateEvent` returns false and `canUploadPhotos` returns false for the offending metric, while unrelated actions remain permitted.
**Validates: Requirements 10.2, 10.3, 10.4**

### Property 10: Payment-outcome correctness
*For any* checkout outcome, a provider-confirmed **success** yields Pro access plus a `succeeded` Payment record, whereas a **failure** or **cancel** yields no Pro access and no `succeeded` Payment record.
**Validates: Requirements 16.1, 16.2, 16.3, 12.4**

*(Property reflection: candidate properties "active subscription grants Pro" and "canceled-in-period grants Pro" were consolidated into Property 4; "photo count gate" and "storage byte gate" remain distinct — Properties 2 and 3 — because they exercise different metrics and generators.)*

## Error Handling

- **Event limit** — friendly upgrade-or-archive message via `Inertia::flash('toast', ['type'=>'error', ...])` and `return back()`; no generic HTTP error. _(R15.1)_
- **Photo/storage limit (public upload)** — `abort(403, <friendly upgrade message>)`; nothing stored or dispatched. _(R8.2, R9.3, R15.2)_
- **Upgrade CTA** — all limit messages reference the Billing page as the call to action. _(R15.3)_
- **Invalid webhook signature** — 4xx (`abort(400)`), no state change. _(R17.3)_
- **Duplicate webhook** — 200 no-op via `webhook_events` unique constraint. _(R18.2)_
- **Provider errors** — never surfaced raw to users; caught and mapped to a neutral message. _(R22.7)_
- **Secrets** — never logged; only `public_key` reaches the frontend. _(R22.5, R22.7)_
- **Validation** — all billing input via FormRequests. _(R22.4)_
- **Transactions** — webhook processing and checkout confirmation wrapped in `DB::transaction`. _(R17.4)_
- **Mass assignment** — `status`, `provider`, `provider_subscription_id`, `provider_payment_id` guarded on models. _(R22.3)_

## Testing Strategy

All tests use the `FakePaymentProvider` with no real credentials, on in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `RefreshDatabase`. PBT **applies** here (pure limit logic, deterministic provider) for Properties 1–10; each property is implemented by a single property-based test at ≥100 iterations using a PHP PBT library, tagged `Feature: saas-billing, Property {n}: {text}`, complemented by example/integration tests for concrete flows.

**Dual approach:**
- *Property tests* — Properties 1–10 (limit gates, Pro-access, webhook idempotency/signature, IDOR, payment outcome, downgrade).
- *Unit/example tests* — plan config loading and env-tunability; specific webhook event types updating state; Billing page Inertia props via `AssertableInertia`.

**Per-property test files (indicative):** `PlanConfigTest`, `EventLimitTest`, `PhotoLimitTest`, `StorageLimitTest`, `ProAccessTest`, `PaymentTest`, `WebhookSignatureTest`, `WebhookIdempotencyTest`, `AuthorizationTest`, `DowngradeTest`, `BillingPagePropsTest`.

**Factories:** `SubscriptionFactory` with states `active`, `canceledButActive`/`onGracePeriod`, `expired`, `pastDue`, `incomplete`; `PaymentFactory` with `succeeded`/`failed` states — alongside existing `User`/`Event`/`Photo` factories.

**Queue assertions:** on rejected uploads, assert `ProcessPhoto` is **not** dispatched (`Queue::fake()` + `assertNotPushed`, or dispatched-count zero). On accepted batches, assert exactly `N` dispatched.

**Simulating webhooks in tests:** build a JSON payload, sign it with `PAYMENT_WEBHOOK_SECRET` (HMAC), and POST to `billing/webhook`. Assert idempotency by posting the same `provider_event_id` twice and checking a single effect. Assert signature rejection with a tampered body/signature.

**Regression:** full `php artisan test` (195 existing tests remain green), `tsc`, and `npm run build` must pass. _(R25, R28)_

## Design Decisions

- **A — Provider-agnostic with a Fake default.** All provider code lives behind `PaymentProviderInterface`; `FakePaymentProvider` is the default. Rationale: testability without credentials and deterministic success/failure/cancel; the entire suite runs locally. Only the Fake adapter ships now — the real PayMongo adapter is a documented, swappable slot (interface + config seam + docs), deferred to a future production phase. _(R3, R4, R26)_
- **B — Config-backed plans, DB source of truth.** Plans live in `config/plans.php` (env-tunable, mirrors `config/uploads.php`); subscriptions/payments are DB tables and the authoritative record of who has Pro. Rationale: limits are tunable without code changes, and no billing state is smeared onto `users`. _(R1, R2, R6)_
- **C — Storage = live `SUM(file_size)`.** Rationale: accurate, no counter column to drift, no filesystem scan. Explicitly tracks originals only (optimized/thumbnail variants consume disk but are not separately sized in the DB). _(R6.4, R9, R14)_
- **D — Atomic reject on over-limit batches.** A batch that would breach the photo or storage limit is rejected whole (403 + friendly message), storing nothing and dispatching nothing. Rationale: predictable, un-gameable behavior; cannot be bypassed by splitting a batch. _(R8, R9)_
- **Webhook CSRF exclusion.** Done via `$middleware->validateCsrfTokens(except: ['billing/webhook'])` in `bootstrap/app.php` (Laravel 12/13 API) rather than a per-route override. _(R17.1, R22.2)_
- **Limit checks live in `BillingService`, not middleware.** Rationale: keeps enforcement in one authoritative place, avoids middleware sprawl, and is directly unit/property-testable. A minimal policy is used only where resource ownership needs it — though IDOR is naturally avoided by always scoping to `$request->user()`. _(R23)_
- **Grace-period logic.** A `canceled` subscription with `current_period_end` in the future still grants Pro (`onGracePeriod`); `cancel_at_period_end=true` does not immediately downgrade. _(R6.5, R19.3, R11.3)_
- **Success is applied by the webhook (authoritative); the verified return is a convenience.** Idempotency (Property 5 / `webhook_events` unique) guarantees no double-apply if both the return and the webhook fire. _(R16.4, R18)_
- **Mass-assignment guarding.** `status`, `provider`, and provider id columns are guarded so client input can never set billing state. _(R22.3)_
```