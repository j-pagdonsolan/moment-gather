# MomentGather Billing (SaaS Foundation — Phase 12)

## Overview

MomentGather ships a SaaS billing foundation with two plans, **Free** and **Pro**,
and plan-based usage limits (active events, photos per event, storage). The
foundation is **local-only** — no Redis/Horizon/S3/cloud dependencies — and the
**backend is authoritative**: every plan, limit, and access decision is made
server-side by `App\Billing\BillingService`. The frontend never decides
permissions or limits; it only renders backend-computed values.

The payment integration is **provider-agnostic**. All provider-specific behavior
sits behind a single interface, and the default adapter is a credential-free fake
that powers both local development and the entire automated test suite
deterministically. A real provider is a documented, swappable slot that is **not**
implemented in this phase.

## Plans & limits

Plans are **config-backed** in `config/plans.php` (mirroring the `config/uploads.php`
pattern) and are **env-tunable** via `PLAN_*` variables. There is no `plans`
database table; a plan is a config-derived value object (`App\Billing\Plan`).

| Plan | Slug | Price | Interval | Max active events | Max photos / event | Max storage |
|------|------|-------|----------|-------------------|--------------------|-------------|
| Free | `free` | 0 | — | 1 | 100 | 500 MB (`524288000` bytes) |
| Pro  | `pro`  | `49900` minor units (monthly) | `monthly` | 10 | 5000 | 10 GB (`10737418240` bytes) |

Every limit and the Pro price are overridable via environment variables consumed
by `config/plans.php` (see [Environment variables](#environment-variables)).
Prices are stored in **minor currency units** (e.g. centavos).

## Billing architecture

The feature is layered so provider-specific code is confined to a single adapter.

- **Provider seam** — `App\Billing\Contracts\PaymentProviderInterface` is the only
  abstraction the rest of the app depends on. It declares
  `createCheckoutSession`, `verifyWebhookSignature`, `parseWebhook`, and
  `cancelSubscription`. The active provider is resolved from
  `config('billing.provider')` via a container binding, so controllers depend only
  on the interface.
- **Authoritative service** — `App\Billing\BillingService` is the single source of
  truth for plan/subscription queries and all limit checks: `currentPlan`,
  `isPro`, `hasActiveSubscription`, `canCreateEvent`,
  `canUploadPhotos`, `storageUsedBytes`, `activeEventCount`, and `usageSummary`.
- **Database is the source of truth for who has Pro** — three tables:
  - `subscriptions` — one row per subscription (plan, provider, status, period
    window, cancel flags). `provider_subscription_id` is UNIQUE.
  - `payments` — payment history (amount in minor units, currency, status,
    `paid_at`, non-sensitive `metadata`). `provider_payment_id` is UNIQUE.
  - `webhook_events` — an idempotency ledger keyed by a UNIQUE
    `provider_event_id`.

  No billing columns are added to `users`.
- **Plan value object** — `App\Billing\Plan` is a `final readonly` value object
  built from config via `Plan::fromConfig($slug)`, falling back to `free` when the
  slug is undefined.
- **Storage usage** — computed live as `SUM(photos.file_size)` over the owner's
  non-soft-deleted photos across their non-soft-deleted events. There is no counter
  column, so there is no drift to reconcile.

## Payment provider

`App\Billing\Providers\FakePaymentProvider` is the default adapter
(`config('billing.provider') = 'fake'`). It is deterministic and credential-free:

- It signs its hosted-checkout tokens and its webhook payloads with HMAC-SHA256
  keyed by the webhook secret (falling back to a fixed key when no secret is set),
  so signature verification and idempotency can be exercised end-to-end with no
  real provider.
- `createCheckoutSession` returns a URL to the **local** fake hosted-checkout route
  carrying a signed token (user id + plan + nonce).
- It powers local development and the **entire test suite**.

A real provider — **PayMongo**, intended for Philippine users — is a documented,
**swappable slot**. Adding it later means implementing
`PaymentProviderInterface` in a new adapter and setting `PAYMENT_PROVIDER`
accordingly in a future production phase. **The real adapter is NOT implemented in
this phase.**

## Environment variables

Configured in `.env` (defaults live in `config/billing.php` and `config/plans.php`).
Use placeholders — **never commit real secret values**.

| Variable | Purpose | Exposure |
|----------|---------|----------|
| `PAYMENT_PROVIDER` | Active provider driver (`fake` default) | server-side |
| `PAYMENT_PUBLIC_KEY` | Publishable key | **only key ever sent to the frontend** |
| `PAYMENT_SECRET_KEY` | Provider secret key | server-side only |
| `PAYMENT_WEBHOOK_SECRET` | HMAC key for webhook signature verification | server-side only |
| `BILLING_CURRENCY` | Currency code (`PHP` default) | server-side |
| `PLAN_FREE_MAX_ACTIVE_EVENTS` | Free active-event limit | server-side |
| `PLAN_FREE_MAX_PHOTOS_PER_EVENT` | Free photos-per-event limit | server-side |
| `PLAN_FREE_MAX_STORAGE_BYTES` | Free storage limit (bytes) | server-side |
| `PLAN_PRO_PRICE` | Pro price (minor units) | server-side |
| `PLAN_PRO_BILLING_INTERVAL` | Pro interval (`monthly`) | server-side |
| `PLAN_PRO_MAX_ACTIVE_EVENTS` | Pro active-event limit | server-side |
| `PLAN_PRO_MAX_PHOTOS_PER_EVENT` | Pro photos-per-event limit | server-side |
| `PLAN_PRO_MAX_STORAGE_BYTES` | Pro storage limit (bytes) | server-side |

Only the **public** key is ever exposed to the frontend (via the Billing page's
`publicKey` Inertia prop). The secret key and webhook secret stay server-side and
are **never committed or logged**.

Example (placeholders only):

```dotenv
# Billing / payments (SaaS)
PAYMENT_PROVIDER=fake
PAYMENT_PUBLIC_KEY=
PAYMENT_SECRET_KEY=
PAYMENT_WEBHOOK_SECRET=
BILLING_CURRENCY=PHP
```

## Local sandbox / fake setup

With `PAYMENT_PROVIDER=fake` (the default), the upgrade flow works **end-to-end
locally**, no external accounts or tunneling required:

1. **Billing page** (`GET /billing`) → **Upgrade**.
2. `POST /billing/checkout` asks the fake provider for a checkout session and
   redirects to the **fake hosted checkout** (`GET /billing/fake-checkout`).
3. The fake checkout page offers **Success / Fail / Cancel**.
4. The chosen outcome posts to `POST /billing/fake-checkout/complete`, which makes
   the fake provider emit a **signed webhook in-process** to `POST /billing/webhook`
   and then redirects to the appropriate return route
   (`billing.checkout.success` or `billing.checkout.cancel`).
5. The webhook applies the authoritative subscription/payment state change.

No tunneling is needed for the fake provider because the webhook is delivered
in-process. A **real** provider would require a **public webhook URL** (e.g. a
tunnel) in a future phase — documented here, not implemented now.

## Webhook setup

- **Endpoint:** `POST /billing/webhook` — **public** (no auth) and
  **CSRF-excluded** (configured in `bootstrap/app.php` via
  `validateCsrfTokens(except: ['billing/webhook'])`).
- **Authenticity:** every request is **signature-verified**. The controller
  recomputes HMAC-SHA256 over the raw body using `PAYMENT_WEBHOOK_SECRET` and
  compares against the `X-Signature` header. An invalid signature → `400` with
  **no state change**.
- **Idempotency:** each event is recorded in `webhook_events` keyed by
  `provider_event_id` (UNIQUE). A duplicate delivery is a `200` no-op — exactly one
  effect per event id, even under concurrent duplicates.

Handled normalized event types (routed in `WebhookController::applyEvent`):

- `payment_succeeded` — activate subscription + record a `succeeded` payment.
- `subscription_created` — upsert subscription as `active`.
- `subscription_updated` — upsert subscription as `active`.
- `subscription_canceled` — keep grace period (future period → `canceled` +
  `cancel_at_period_end`; otherwise `expired`).
- `payment_failed` — record a `failed` payment; never activates Pro.
- `subscription_expired` — set status `expired`.
- `past_due` — set status `past_due`.

Secrets are never logged and raw provider errors are never surfaced to the caller.

## Testing instructions

All billing tests use the `FakePaymentProvider`, in-memory SQLite,
`RefreshDatabase`, and `Queue::fake()` where dispatch is asserted. **No real
credentials are needed.**

Run the whole billing suite:

```bash
php artisan test --filter=Billing
```

Or run individual suites:

```bash
php artisan test --filter=PlanLimitsTest
php artisan test --filter=EventLimitTest
php artisan test --filter=PhotoLimitTest
php artisan test --filter=StorageLimitTest
php artisan test --filter=SubscriptionAccessTest
php artisan test --filter=PaymentTest
php artisan test --filter=WebhookTest
php artisan test --filter=BillingAuthorizationTest
php artisan test --filter=DowngradeTest
php artisan test --filter=BillingPageTest
```

**Simulating outcomes:**

- **Success / failure / cancel** are simulated through the fake checkout outcomes
  (`Success`, `Fail`, `Cancel`), which drive the corresponding signed webhook and
  return route.
- **Webhook tests** build signed payloads directly via
  `FakePaymentProvider::makeWebhookPayload(...)` to construct the body and
  `FakePaymentProvider::signPayload(...)` to produce the `X-Signature` header.
  This exercises signature integrity and idempotency without any real provider.

## How limits are enforced

All enforcement is **server-side**:

- **Event limit** — `EventController@store` calls
  `BillingService::canCreateEvent($user)` inside the existing `DB::transaction`
  before creating. If over the limit it flashes a friendly error toast
  (referencing the Billing page) and returns `back()` **without creating**.
  Viewing, editing, and archiving are never blocked.
- **Photo + storage limits** — `PublicPhotoUploadController@store` resolves the
  event owner and calls
  `BillingService::canUploadPhotos($owner, $event, $incomingCount, $incomingBytes)`.
  If it returns false, the request is rejected **atomically** with `abort(403, ...)`
  and a friendly upgrade message — **before** anything is stored or any
  `ProcessPhoto` job is dispatched. The batch is all-or-nothing and cannot be
  bypassed by splitting it.
- **Abuse cap coexistence** — the existing per-event abuse cap
  (`config('uploads.max_per_event')`) remains a **separate** atomic gate that runs
  alongside the plan gate; both must pass.

## How subscription states sync

- The **webhook is authoritative** for all state changes. The verified checkout
  return (`billing.checkout.success`) is a **UX convenience** only — idempotency
  ensures no double-apply if both the webhook and the return fire.
- **Grace period** — a `canceled` subscription with `cancel_at_period_end` and a
  **future** `current_period_end` still grants Pro (`Subscription::isActiveNow()`
  and `BillingService::isPro()`).
- **Internal status set** — `{active, past_due, canceled, expired, incomplete,
  trialing}` (constants on `App\Models\Subscription`). These are never surfaced raw
  to the frontend.
- **Provider → internal mapping** — the fake provider emits internal-shaped
  statuses directly; a real adapter maps its own vocabulary into this internal set.

## Downgrade behavior

- Downgrading (Pro → Free) **never deletes** content — no events or photos are
  removed.
- Being over-limit **blocks new creates and new uploads** (via `canCreateEvent` /
  `canUploadPhotos`) but **never blocks viewing** existing events or photos.
