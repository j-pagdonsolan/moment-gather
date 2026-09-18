# Implementation Plan: SaaS Billing (Phase 12)

## Overview

This plan implements the MomentGather Phase 12 SaaS billing foundation as an incremental,
dependency-ordered sequence of coding tasks derived from `requirements.md` and `design.md`.
Work flows bottom-up through the design's layers so every dependency lands before its consumer:
Config → Data (models/migrations) → Domain (Plan, BillingService, provider seam) →
existing-controller limit gates → Billing HTTP layer (controllers, routes, webhook, CSRF) →
frontend billing UI → factories + property/example tests → docs + final verification.

Design decisions honored throughout: provider-agnostic behind `PaymentProviderInterface`
with `FakePaymentProvider` default (Decision A); config-backed env-tunable plans (Decision B);
storage via live `SUM(file_size)` (Decision C); atomic reject on over-limit batches (Decision D);
backend authoritative; Phases 1–11 preserved; no secret keys to the frontend; never store card data.

Each task is tagged `[NEW]` or `[MODIFY]`, references specific requirement sub-clauses, and — for
tests — references the design's Property numbers. Language: **PHP (Laravel 13)** backend and
**TypeScript/React 19 (Inertia v3)** frontend, matching the design (no pseudocode).

Regression note (verified against the current suite): `user_can_own_multiple_events` in
`tests/Feature/EventTest.php` creates events via `Event::factory()` (direct DB), NOT via
`POST /events`, so it bypasses the `EventController@store` gate. Every controller-based
`POST /events` in `EventTest`/`EventValidationTest` uses a fresh user with a single creation
attempt (≤ 1 active event per user). Therefore the Free-tier `max_active_events=1` gate does
not conflict with existing controller-store tests. Do not weaken the gate. If a future genuine
conflict surfaces during Wave 8 verification, STOP and report to the orchestrator.

## Tasks

- [x] 1. Config + schema foundation
  - [x] 1.1 [NEW] Create `config/plans.php` and `config/billing.php`
    - `config/plans.php`: array keyed by slug. Free (`slug=free`, `name=Free`, `price=0`, `billing_interval=null`, `max_active_events=1`, `max_photos_per_event=100`, `max_storage_bytes=500*1024*1024`) and Pro (`slug=pro`, `name=Pro`, `price` env, `billing_interval=monthly`, `max_active_events=10`, `max_photos_per_event=5000`, `max_storage_bytes=10*1024*1024*1024`). Every limit/price env-tunable via `PLAN_*` per the design shape, mirroring `config/uploads.php`.
    - `config/billing.php`: `provider=env('PAYMENT_PROVIDER','fake')`, `currency=env('BILLING_CURRENCY','PHP')`, `public_key=env('PAYMENT_PUBLIC_KEY')`, `secret_key=env('PAYMENT_SECRET_KEY')` (server-only), `webhook_secret=env('PAYMENT_WEBHOOK_SECRET')` (server-only).
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 4.1, 5_

  - [x] 1.2 [MODIFY] Add billing vars to `.env.example`
    - Alongside existing `UPLOAD_*`/`BROWSE_*`: `PAYMENT_PROVIDER=fake`, `PAYMENT_PUBLIC_KEY=`, `PAYMENT_SECRET_KEY=`, `PAYMENT_WEBHOOK_SECRET=`, `BILLING_CURRENCY=PHP`, and the `PLAN_*` tuning vars consumed by `config/plans.php`. No real secret values.
    - _Requirements: 5.1, 5.4_

  - [x] 1.3 [NEW] Migration `create_subscriptions_table`
    - Columns per Data Models: `user_id` FK→users `cascadeOnDelete` (indexed), `plan` string(50), `provider` string(50), `provider_subscription_id` string(191) nullable UNIQUE, `status` string(20), `current_period_start` timestamp nullable, `current_period_end` timestamp nullable, `cancel_at_period_end` boolean default `false`, `canceled_at` timestamp nullable, timestamps.
    - _Requirements: 2.1, 2.4, 2.5, 27.1, 27.2, 27.3, 27.4_

  - [x] 1.4 [NEW] Migration `create_payments_table`
    - Columns per Data Models: `user_id` FK→users (indexed), `subscription_id` FK→subscriptions nullable `nullOnDelete`, `provider` string(50), `provider_payment_id` string(191) nullable UNIQUE, `amount` integer (minor units), `currency` string(3), `status` string(20), `paid_at` timestamp nullable, `metadata` json nullable, timestamps.
    - _Requirements: 2.2, 2.4, 2.5, 27.1, 27.2, 27.3, 27.4_

  - [x] 1.5 [NEW] Migration `create_webhook_events_table`
    - Columns per Data Models: `provider_event_id` string(191) UNIQUE (idempotency key), `type` string(100), timestamps.
    - _Requirements: 2.3, 18.1, 27.1_

- [x] 2. Checkpoint - schema validity
  - Run `php artisan config:clear`, then `php artisan migrate` (against the in-memory/sqlite test path) and confirm all three tables build with constraints/indexes. Ensure all tests pass; ask the user if questions arise.

- [x] 3. Models, Plan value object, provider contract + DTOs
  - [x] 3.1 [NEW] `app/Models/Subscription.php`
    - `belongsTo(User)`; guard `status`, `provider`, `provider_subscription_id` from mass assignment; casts `current_period_start`/`current_period_end`/`canceled_at`→datetime, `cancel_at_period_end`→bool; `STATUS_*` constants `{active, past_due, canceled, expired, incomplete, trialing}`; `scopeActive()`, `scopeOnPlan($slug)`; helpers `isActiveNow()` and `onGracePeriod()` (canceled with `current_period_end` in the future).
    - _Requirements: 2.1, 6.5, 11.1, 19.3, 22.3_

  - [x] 3.2 [NEW] `app/Models/Payment.php`
    - `belongsTo(User)`, `belongsTo(Subscription)`; guard `provider`, `provider_payment_id`, `status`; casts `amount`→int, `paid_at`→datetime, `metadata`→array.
    - _Requirements: 2.2, 20.1, 22.3_

  - [x] 3.3 [NEW] `app/Models/WebhookEvent.php`
    - Fields `provider_event_id` (unique), `type`, timestamps; minimal idempotency ledger.
    - _Requirements: 2.3, 18.1_

  - [x] 3.4 [MODIFY] `app/Models/User.php`
    - Add `subscriptions(): HasMany` and `payments(): HasMany`. Do NOT add any billing columns to `users`; leave `#[Fillable(['name','email','password'])]` unchanged.
    - _Requirements: 2.1, 2.2, 21.2_

  - [x] 3.5 [NEW] `app/Billing/Plan.php`
    - `final readonly` value object with `slug`, `name`, `price`, `billingInterval`, `maxActiveEvents`, `maxPhotosPerEvent`, `maxStorageBytes`; static `fromConfig(string $slug)` reading `config('plans')`, falling back to `free` when the slug is undefined.
    - _Requirements: 1.6, 1.7_

  - [x] 3.6 [NEW] Provider contract + DTOs
    - `app/Billing/Contracts/PaymentProviderInterface.php` declaring `createCheckoutSession(User, string $planSlug)`, `verifyWebhookSignature(string $payload, ?string $signatureHeader, string $secret): bool`, `parseWebhook(string $payload): NormalizedWebhookEvent`, `cancelSubscription(Subscription): void`.
    - `app/Billing/DTO/CheckoutSession.php` (readonly `url`, `providerRef`) and `app/Billing/DTO/NormalizedWebhookEvent.php` (readonly `providerEventId`, `type`, `data`).
    - _Requirements: 3.3, 3.4, 4.1_

- [x] 4. Domain service + provider + binding
  - [x] 4.1 [NEW] `app/Billing/BillingService.php`
    - `currentPlan(User): Plan` (Pro if `isPro`, else Free via `Plan::fromConfig`); `isPro(User): bool` and `hasActiveSubscription(User): bool` (true for active, or canceled with `current_period_end` in the future; false for expired/incomplete/failed-only); `canCreateEvent(User): bool` (`activeEventCount < maxActiveEvents`); `canUploadPhotos(User $owner, Event $event, int $incomingCount, int $incomingBytes): bool` (event non-deleted photos + count ≤ maxPhotosPerEvent AND `storageUsedBytes(owner)` + bytes ≤ maxStorageBytes); `storageUsedBytes(User): int` (`SUM(photos.file_size)` joined via owner's events, non-soft-deleted photos only); `usageSummary(User): array` (`events/photos/storage` as `{used,limit}`). `activeEventCount` counts `status='active'` and not soft-deleted only. Uses Eloquent parameter binding throughout.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 7.5, 9.1, 11.3, 11.4, 11.5, 14.1, 22.6, 23.1_

  - [x] 4.2 [NEW] `app/Billing/Providers/FakePaymentProvider.php`
    - `createCheckoutSession` → `CheckoutSession` whose `url` targets the `billing.fake-checkout` route carrying a signed token (user id + plan + nonce, HMAC with `PAYMENT_WEBHOOK_SECRET`); `verifyWebhookSignature` recomputes HMAC-SHA256 over the raw body with `PAYMENT_WEBHOOK_SECRET` and compares via `hash_equals`; `parseWebhook` decodes the JSON body into `NormalizedWebhookEvent`; `cancelSubscription` no-op/deterministic; plus a public test helper to build a signed webhook payload for the fake checkout page and tests.
    - _Requirements: 4.2, 4.3, 4.4, 4.6, 12.3, 17.2_

  - [x] 4.3 [NEW] `app/Providers/BillingServiceProvider.php`
    - Bind `PaymentProviderInterface` to the concrete provider chosen by `config('billing.provider')` (`fake` → `FakePaymentProvider`); register in `bootstrap/providers.php`.
    - _Requirements: 3.3, 4.1, 4.2_

- [x] 5. Limit gates on existing controllers
  - [x] 5.1 [MODIFY] `app/Http/Controllers/EventController.php@store`
    - Resolve `BillingService`; inside the existing `DB::transaction`, BEFORE creating: if `! canCreateEvent($request->user())` → `Inertia::flash('toast', ['type'=>'error', ...upgrade-or-archive message referencing the Billing page])` and `return back()` without creating. Preserve the existing success path (`status='active'`, `upload_enabled=true`, slug generation, success toast, `to_route('events.show')`). Do NOT block viewing/editing/archiving.
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 10.2, 15.1, 15.3_

  - [x] 5.2 [MODIFY] `app/Http/Controllers/PublicPhotoUploadController.php@store`
    - After the active-event lookup and `upload_enabled` check, and coexisting with the existing abuse cap (`config('uploads.max_per_event')`) as a separate atomic gate: resolve the event owner (`$event->user`) and `BillingService`; `$incomingCount = count($request->file('photos'))`; `$incomingBytes = array_sum(array_map(fn($f) => $f->getSize(), $request->file('photos')))`; if `! canUploadPhotos($owner, $event, $incomingCount, $incomingBytes)` → `abort(403, <friendly upgrade message referencing the Billing page>)`, storing nothing and dispatching nothing. Otherwise the existing store + `ProcessPhoto::dispatch` loop runs unchanged. Plan gate scoped to the target event; not bypassable by splitting a batch.
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 9.1, 9.2, 9.3, 9.4, 9.5, 10.3, 15.2, 15.3_

- [x] 6. Checkpoint - gates do not regress Phase 1–11
  - Run the existing event and upload tests (e.g. `php artisan test --filter=EventTest`, `--filter=EventValidationTest`, `--filter=EventPhotoCapTest`, `--filter=EventQrCodeTest`) and confirm the Free-tier `max_active_events=1` gate does not break controller-store tests (verified: multi-event tests use `Event::factory()`, not `POST /events`). If a genuine controller-store conflict appears, STOP and report to the orchestrator — do not weaken the gate. Ask the user if questions arise.

- [x] 7. Billing HTTP layer, routes, webhook, CSRF
  - [x] 7.1 [NEW] `app/Http/Controllers/Billing/BillingController.php@show`
    - `Inertia::render('settings/billing', [...])` with current plan, subscription status, price, interval, plan limits, current period, `usageSummary`, owner-scoped payment history, and `publicKey` (`config('billing.public_key')` only — never a secret). All values via `BillingService`, scoped to `$request->user()`.
    - _Requirements: 13.2, 13.3, 13.4, 13.5, 13.6, 20.1, 20.2, 20.3, 24.1, 24.2_

  - [x] 7.2 [NEW] `app/Http/Controllers/Billing/CheckoutController.php` + Billing FormRequest
    - `store` → `provider->createCheckoutSession($request->user(), 'pro')` then redirect to the session URL; `success` → verified return, flash success toast, redirect to Billing (state authority remains the webhook); `cancel` → flash neutral toast, redirect to Billing, no Pro granted. Add `app/Http/Requests/Billing/*` FormRequest validating the plan slug against an allowlist. No raw card data processed by Laravel.
    - _Requirements: 12.1, 12.2, 12.4, 12.5, 16.1, 16.3, 22.4_

  - [x] 7.3 [NEW] `app/Http/Controllers/Billing/CancelSubscriptionController.php@store`
    - Resolve the requesting user's active subscription, call `provider->cancelSubscription`, set local `cancel_at_period_end=true`, flash a toast. Scoped to `$request->user()`.
    - _Requirements: 19.1, 19.3_

  - [x] 7.4 [NEW] `app/Http/Controllers/Billing/WebhookController.php@handle`
    - `verifyWebhookSignature(raw body, header, secret)` → `abort(400)` with no state change on invalid; `parseWebhook`; INSERT `webhook_events.provider_event_id` → return `200` no-op on unique violation (duplicate); `DB::transaction` upsert subscription and/or payment by `type` (`payment_succeeded`, `subscription_created`, `subscription_updated`, `subscription_canceled`, `payment_failed`, `subscription_expired`, `past_due`); return `200`. Never log secrets; never surface raw provider errors.
    - _Requirements: 16.1, 16.2, 16.4, 17.2, 17.3, 17.4, 17.5, 18.1, 18.2, 18.3, 22.1, 22.7, 22.8_

  - [x] 7.5 [NEW] Fake hosted-checkout backend glue
    - `GET billing/fake-checkout` (auth) renders `Billing/FakeCheckout`; its Success/Fail/Cancel actions cause the fake provider to emit the signed webhook to `billing/webhook` (server-side) and redirect to the appropriate return route. Keep it a clearly-labelled dev/test surface.
    - _Requirements: 4.3, 12.3, 16.2, 16.3_

  - [x] 7.6 [NEW] `routes/web.php` billing routes
    - Under `['auth','verified']`: `GET billing`→`billing.show`, `POST billing/checkout`→`billing.checkout`, `GET billing/checkout/success`→`billing.checkout.success`, `GET billing/checkout/cancel`→`billing.checkout.cancel`, `POST billing/cancel`→`billing.cancel`, `GET billing/fake-checkout`→`billing.fake-checkout`. Public (no auth): `POST billing/webhook`→`billing.webhook`.
    - _Requirements: 12.1, 12.2, 13.1, 17.1, 19.1, 21.1_

  - [x] 7.7 [MODIFY] `bootstrap/app.php` CSRF exclusion
    - Inside `withMiddleware`, add `$middleware->validateCsrfTokens(except: ['billing/webhook']);`. Leave existing `web(append: [...])` and cookie encryption unchanged.
    - _Requirements: 17.1, 22.2_

  - [x]* 7.8 [MODIFY] `app/Http/Middleware/HandleInertiaRequests.php` shared billing prop (optional)
    - Optionally share a minimal `billing` prop `{ plan: slug, isPro: bool }` computed via `BillingService` for nav badges. Never share secret keys; keep shared props minimal.
    - _Requirements: 24.1_

- [x] 8. Checkpoint - billing HTTP layer wired
  - Run `php artisan route:list --path=billing` and confirm all billing routes register with the webhook route public + CSRF-excluded and the rest under auth+verified. Ensure all tests pass; ask the user if questions arise.

- [x] 9. Frontend billing UI
  - [x] 9.1 [NEW] `resources/js/types/billing.ts`
    - Types `Plan`, `Subscription`, `Payment`, `BillingUsage`, `BillingPageProps` matching the backend Inertia props.
    - _Requirements: 24.1_

  - [x] 9.2 [NEW] Billing components
    - `PlanCard` (name, price, limits, current-plan/CTA state); `UsageMeter` (progress bar with `normal | approaching | reached` states from backend used/limit); `PaymentHistoryList` (date, amount, currency, status, reference; owner-only); `CancelSubscriptionDialog` (confirmation before cancel).
    - _Requirements: 14.2, 14.3, 14.4, 14.5, 19.2, 20.1, 29.1, 29.3_

  - [x] 9.3 [NEW] `resources/js/pages/settings/billing.tsx`
    - Uses the settings layout; renders plan cards, current status/period, usage meters (events/photos/storage), payment history, upgrade action (Free) or cancel action (active sub). Reuse Phase 11 primitives, `EmptyState`, and the toast infra; mobile-first; props typed via `BillingPageProps`. Frontend decides no permissions.
    - _Requirements: 13.2, 13.3, 13.4, 13.5, 13.6, 13.7, 14.2, 20.1, 24.2, 24.3, 29.1, 29.2, 29.5_

  - [x] 9.4 [NEW] `resources/js/pages/Billing/FakeCheckout.tsx`
    - Clearly-labelled dev/test hosted-checkout surface with Success / Fail / Cancel buttons that post to the appropriate return route and trigger the fake signed webhook.
    - _Requirements: 4.3, 12.3_

  - [x] 9.5 [MODIFY] `resources/js/layouts/settings/layout.tsx`
    - Add a Billing entry to `sidebarNavItems`, mirroring Profile/Security/Appearance.
    - _Requirements: 13.1, 29.2_

- [x] 10. Checkpoint - frontend type-checks
  - Run `npx tsc --noEmit` and confirm the billing types/pages/components type-check. Ensure all tests pass; ask the user if questions arise.

- [x] 11. Factories and tests
  - [x] 11.1 [NEW] Billing factories
    - `database/factories/SubscriptionFactory.php` with states `active`, `canceledButActive`/`onGracePeriod` (canceled + `current_period_end` future), `expired`, `pastDue`, `incomplete`; `database/factories/PaymentFactory.php` with `succeeded`/`failed` states. Alongside existing `User`/`Event`/`Photo` factories.
    - _Requirements: 25.10_

  - [x] 11.2 [NEW] `tests/Feature/Billing/PlanLimitsTest.php`
    - Plans exist and Plan_Limits are configurable (env-tunable); Free/Pro limits load correctly and an undefined slug falls back to Free.
    - _Requirements: 25.1, 1.2, 1.3, 1.5, 1.7_

  - [x]* 11.3 [NEW] `tests/Feature/Billing/EventLimitTest.php`
    - **Property 1: Event-limit soundness** — a Free user creates up to 1 active event via `POST /events`; the 2nd is blocked with a friendly message and no create; archived/soft-deleted events are not counted; a Pro user (active sub) creates up to 10. ≥100 iterations; tag `Feature: saas-billing, Property 1`.
    - _Requirements: 25.2 — Property 1 (7.1, 7.2, 7.4, 7.5)_

  - [x]* 11.4 [NEW] `tests/Feature/Billing/PhotoLimitTest.php`
    - **Property 2: Photo-limit atomicity** — batch accepted iff `C + N ≤ M`, else 403 with zero stored (Photo count unchanged) and zero dispatched (`Queue::fake()` + `assertNothingPushed`); event isolation holds; batch cannot bypass by splitting. ≥100 iterations; tag `Feature: saas-billing, Property 2`.
    - _Requirements: 25.3 — Property 2 (8.1, 8.2, 8.4, 8.5)_

  - [x]* 11.5 [NEW] `tests/Feature/Billing/StorageLimitTest.php`
    - **Property 3: Storage-limit atomicity** — `storageUsedBytes = SUM(file_size)` computed correctly; batch accepted iff `S + B ≤ L`, else 403 with zero stored and zero dispatched. ≥100 iterations; tag `Feature: saas-billing, Property 3`.
    - _Requirements: 25.4 — Property 3 (9.1, 9.2, 9.3, 9.5)_

  - [x]* 11.6 [NEW] `tests/Feature/Billing/SubscriptionAccessTest.php`
    - **Property 4: Pro-access correctness** — active grants Pro; canceled-in-period grants Pro; expired loses Pro; incomplete/failed-only does not grant Pro. ≥100 iterations; tag `Feature: saas-billing, Property 4`.
    - _Requirements: 25.5 — Property 4 (6.2, 6.5, 6.6, 11.3, 11.4, 11.5)_

  - [x]* 11.7 [NEW] `tests/Feature/Billing/PaymentTest.php`
    - **Property 10: Payment-outcome correctness** — provider-confirmed success yields Pro + a `succeeded` Payment; failure/cancel yields no Pro and no `succeeded` Payment. ≥100 iterations; tag `Feature: saas-billing, Property 10`.
    - _Requirements: 25.6 — Property 10 (16.1, 16.2, 16.3, 12.4)_

  - [x]* 11.8 [NEW] `tests/Feature/Billing/WebhookTest.php`
    - **Property 5: Webhook idempotency** and **Property 6: Webhook signature integrity** — valid signature accepted + state updated; invalid signature → 4xx and no state change (no subscription/payment/webhook_events row); duplicate `provider_event_id` posted twice → exactly one effect; supported event types update state. Build signed payloads via the `FakePaymentProvider` helper. ≥100 iterations; tags `Feature: saas-billing, Property 5` and `Property 6`.
    - _Requirements: 25.7 — Property 5 (18.1, 18.2, 18.3, 22.8), Property 6 (17.2, 17.3, 22.1)_

  - [x]* 11.9 [NEW] `tests/Feature/Billing/BillingAuthorizationTest.php`
    - **Property 8: Authorization / IDOR safety** — user A cannot view or modify user B's billing/subscription/payment resources; all billing routes require auth. ≥100 iterations; tag `Feature: saas-billing, Property 8`.
    - _Requirements: 25.8 — Property 8 (21.1, 21.2, 21.3)_

  - [x]* 11.10 [NEW] `tests/Feature/Billing/DowngradeTest.php`
    - **Property 7: Downgrade content preservation** and **Property 9: Downgrade limit enforcement** — downgrade deletes no events/photos; over-limit blocks new create and new uploads but never blocks viewing. ≥100 iterations; tags `Feature: saas-billing, Property 7` and `Property 9`.
    - _Requirements: 25.9 — Property 7 (10.1, 10.2, 10.3, 7.3), Property 9 (10.2, 10.3, 10.4)_

  - [x] 11.11 [NEW] `tests/Feature/Billing/BillingPageTest.php`
    - Billing page renders Inertia props (plan, usage, limits, status, period, payment history) for the owner via `AssertableInertia`; `publicKey` present; secret key NOT present in props.
    - _Requirements: 13.2, 13.3, 20.2, 24.1, 22.5_

- [x] 12. Documentation and final verification
  - [x] 12.1 [NEW] Billing documentation
    - Add a billing doc (`docs/billing.md` or a README/.kiro section): plan structure/limits, billing architecture, provider (Fake now, PayMongo future slot), `PAYMENT_*`/`BILLING_*`/`PLAN_*` env vars, local sandbox/fake setup, how to run payment tests, how to simulate success/failure/cancel, how to test webhooks locally without tunneling (noting a real provider would need a public webhook URL later), how limits are enforced, and how subscription states sync. No real secrets; no production infra requirements.
    - _Requirements: 26.1, 26.2, 26.3, 26.4_

  - [x] 12.2 Final verification
    - Run the full `php artisan test` (expect the 195 prior tests + new billing tests green), `npx tsc --noEmit`, and `npm run build`. Confirm no secret keys appear in shared Inertia props. Fix genuine issues on the frontend/new-code side without weakening security or limits. If a prior controller-store test conflicts with the Free gate, STOP and report per the Wave 6 note. Document results.
    - _Requirements: 25.11, 28.1, 28.2, 28.3, 28.4_

## Notes

- Tasks marked with `*` are optional (skippable for a faster MVP): the property-based test tasks
  (11.3–11.10) and the optional shared-prop task (7.8). Core implementation, config-loading tests
  (11.2), billing-page props test (11.11), factories, docs, and final verification are NOT optional.
- Each task references specific requirement sub-clauses; every property test references its design
  Property number for traceability.
- Checkpoints (tasks 2, 6, 8, 10) ensure incremental validation and guard Phase 1–11 regression.
- Property tests validate universal correctness Properties 1–10 at ≥100 iterations using a PHP PBT
  library (tagged `Feature: saas-billing, Property {n}`); example/integration tests cover concrete
  flows. All tests use `FakePaymentProvider`, in-memory SQLite, `RefreshDatabase`, `Queue::fake()`
  where asserting dispatch, and no real credentials.
- Backend is authoritative; the frontend decides no permission or limit. Secret keys and the webhook
  secret are never exposed to the frontend or logged; raw card data is never stored.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3", "1.4", "1.5"] },
    { "id": 1, "tasks": ["3.1", "3.2", "3.3", "3.4", "3.5", "3.6"] },
    { "id": 2, "tasks": ["4.1", "4.2", "4.3"] },
    { "id": 3, "tasks": ["5.1", "5.2"] },
    { "id": 4, "tasks": ["7.1", "7.2", "7.3", "7.4", "7.5", "7.6", "7.7", "7.8"] },
    { "id": 5, "tasks": ["9.1", "9.2", "9.3", "9.4", "9.5"] },
    { "id": 6, "tasks": ["11.1"] },
    { "id": 7, "tasks": ["11.2", "11.3", "11.4", "11.5", "11.6", "11.7", "11.8", "11.9", "11.10", "11.11"] },
    { "id": 8, "tasks": ["12.1"] },
    { "id": 9, "tasks": ["12.2"] }
  ]
}
```
