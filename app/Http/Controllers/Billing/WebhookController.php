<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\Contracts\PaymentProviderInterface;
use App\Billing\DTO\NormalizedWebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public, CSRF-excluded webhook endpoint. This is the authoritative surface that
 * applies subscription/payment state changes from the payment provider.
 *
 * Guarantees:
 *  - Signature integrity (Property 6): an inbound payload whose signature does not
 *    verify against the webhook secret is rejected with a 4xx and produces NO state
 *    change (no subscription, payment, or webhook_events row).
 *  - Idempotency (Property 5): a given provider_event_id produces exactly one effect.
 *    The UNIQUE(provider_event_id) constraint plus the exists() check inside the
 *    transaction guarantee at-most-once application even under concurrent duplicates.
 *
 * Secrets are never logged and raw provider errors are never surfaced to the caller.
 */
class WebhookController extends Controller
{
    /**
     * Handle an inbound provider webhook.
     */
    public function handle(Request $request, PaymentProviderInterface $provider): JsonResponse
    {
        $raw    = $request->getContent();
        $sig    = $request->header('X-Signature');
        $secret = (string) config('billing.webhook_secret');

        // Property 6: invalid signature → 4xx, no state change whatsoever.
        if (! $provider->verifyWebhookSignature($raw, $sig, $secret)) {
            abort(400);
        }

        $event = $provider->parseWebhook($raw);

        // Property 5: idempotency. Record the event id first inside a transaction and
        // rely on the UNIQUE(provider_event_id) constraint. A duplicate is a 200 no-op.
        return DB::transaction(function () use ($event): JsonResponse {
            $already = WebhookEvent::query()
                ->where('provider_event_id', $event->providerEventId)
                ->exists();

            if ($already) {
                return response()->json(['status' => 'ok'], 200);
            }

            try {
                WebhookEvent::create([
                    'provider_event_id' => $event->providerEventId,
                    'type'              => $event->type,
                ]);
            } catch (QueryException $e) {
                // Concurrent duplicate raced past the exists() check and hit the
                // UNIQUE constraint. Treat as an idempotent no-op.
                if ($this->isUniqueViolation($e)) {
                    return response()->json(['status' => 'ok'], 200);
                }

                throw $e;
            }

            $this->applyEvent($event);

            return response()->json(['status' => 'ok'], 200);
        });
    }

    /**
     * Apply the normalized event to subscription/payment state. All writes are
     * server-side and keyed so re-runs never duplicate rows.
     */
    private function applyEvent(NormalizedWebhookEvent $event): void
    {
        $data = $event->data;

        $user = $this->resolveUser($data);

        // If the referenced user does not exist, no-op safely.
        if ($user === null) {
            return;
        }

        match ($event->type) {
            'payment_succeeded'    => $this->handlePaymentSucceeded($user, $data),
            'subscription_created',
            'subscription_updated' => $this->upsertSubscription($user, $data, Subscription::STATUS_ACTIVE),
            'subscription_canceled' => $this->handleSubscriptionCanceled($user, $data),
            'payment_failed'       => $this->handlePaymentFailed($user, $data),
            'subscription_expired' => $this->setSubscriptionStatus($user, $data, Subscription::STATUS_EXPIRED),
            'past_due'             => $this->setSubscriptionStatus($user, $data, Subscription::STATUS_PAST_DUE),
            default                => null,
        };
    }

    /**
     * payment_succeeded: activate the subscription and record a succeeded payment.
     *
     * @param  array<string,mixed>  $data
     */
    private function handlePaymentSucceeded(User $user, array $data): void
    {
        $subscription = $this->upsertSubscription($user, $data, Subscription::STATUS_ACTIVE);

        $providerPaymentId = isset($data['provider_payment_id'])
            ? (string) $data['provider_payment_id']
            : null;

        $attributes = [
            'user_id'         => $user->id,
            'subscription_id' => $subscription?->id,
            'provider'        => (string) config('billing.provider'),
            'amount'          => (int) ($data['amount'] ?? 0),
            'currency'        => (string) ($data['currency'] ?? config('billing.currency')),
            'status'          => Payment::STATUS_SUCCEEDED,
            'paid_at'         => Carbon::now(),
        ];

        // Key by provider_payment_id when present so re-delivery does not duplicate.
        if ($providerPaymentId !== null) {
            Payment::firstOrCreate(
                ['provider_payment_id' => $providerPaymentId],
                $attributes,
            );

            return;
        }

        Payment::create($attributes + ['provider_payment_id' => null]);
    }

    /**
     * payment_failed: record a failed payment. Never activates Pro; the subscription
     * status is left unchanged (incomplete/whatever it currently is).
     *
     * @param  array<string,mixed>  $data
     */
    private function handlePaymentFailed(User $user, array $data): void
    {
        $providerPaymentId = isset($data['provider_payment_id'])
            ? (string) $data['provider_payment_id']
            : null;

        $subscription = $this->findSubscription($user, $data);

        $attributes = [
            'user_id'         => $user->id,
            'subscription_id' => $subscription?->id,
            'provider'        => (string) config('billing.provider'),
            'amount'          => (int) ($data['amount'] ?? 0),
            'currency'        => (string) ($data['currency'] ?? config('billing.currency')),
            'status'          => Payment::STATUS_FAILED,
            'paid_at'         => null,
        ];

        if ($providerPaymentId !== null) {
            Payment::firstOrCreate(
                ['provider_payment_id' => $providerPaymentId],
                $attributes,
            );

            return;
        }

        Payment::create($attributes + ['provider_payment_id' => null]);
    }

    /**
     * subscription_canceled: keep the grace period. If the paid period is still in the
     * future, mark canceled + cancel_at_period_end (still grants Pro until period end);
     * otherwise the subscription has lapsed → expired.
     *
     * @param  array<string,mixed>  $data
     */
    private function handleSubscriptionCanceled(User $user, array $data): void
    {
        $subscription = $this->findSubscription($user, $data);

        if ($subscription === null) {
            return;
        }

        $periodEnd = $this->parseTimestamp($data['period_end'] ?? null);

        if ($periodEnd !== null && $periodEnd->isFuture()) {
            $subscription->status               = Subscription::STATUS_CANCELED;
            $subscription->cancel_at_period_end = true;
            $subscription->canceled_at          = Carbon::now();
            $subscription->current_period_end   = $periodEnd;
        } else {
            $subscription->status      = Subscription::STATUS_EXPIRED;
            $subscription->canceled_at = Carbon::now();
        }

        $subscription->save();
    }

    /**
     * subscription_expired / past_due: set the subscription status directly.
     *
     * @param  array<string,mixed>  $data
     */
    private function setSubscriptionStatus(User $user, array $data, string $status): void
    {
        $subscription = $this->findSubscription($user, $data);

        if ($subscription === null) {
            return;
        }

        $subscription->status = $status;
        $subscription->save();
    }

    /**
     * Upsert the user's subscription from the event data, setting the given status.
     * Keyed by provider_subscription_id when present, else by user_id + provider so
     * re-delivery updates the same row instead of duplicating.
     *
     * @param  array<string,mixed>  $data
     */
    private function upsertSubscription(User $user, array $data, string $status): Subscription
    {
        $provider              = (string) config('billing.provider');
        $providerSubscriptionId = isset($data['provider_subscription_id'])
            ? (string) $data['provider_subscription_id']
            : null;

        $key = $providerSubscriptionId !== null
            ? ['provider_subscription_id' => $providerSubscriptionId]
            : ['user_id' => $user->id, 'provider' => $provider];

        $values = [
            'user_id'                  => $user->id,
            'plan'                     => (string) ($data['plan'] ?? 'pro'),
            'provider'                 => $provider,
            'provider_subscription_id' => $providerSubscriptionId,
            'status'                   => $status,
            'cancel_at_period_end'     => false,
        ];

        $periodStart = $this->parseTimestamp($data['period_start'] ?? null);
        $periodEnd   = $this->parseTimestamp($data['period_end'] ?? null);

        if ($periodStart !== null) {
            $values['current_period_start'] = $periodStart;
        }

        if ($periodEnd !== null) {
            $values['current_period_end'] = $periodEnd;
        }

        return Subscription::updateOrCreate($key, $values);
    }

    /**
     * Find the existing subscription for this user/event without creating one.
     *
     * @param  array<string,mixed>  $data
     */
    private function findSubscription(User $user, array $data): ?Subscription
    {
        $providerSubscriptionId = isset($data['provider_subscription_id'])
            ? (string) $data['provider_subscription_id']
            : null;

        if ($providerSubscriptionId !== null) {
            return Subscription::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->first();
        }

        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('provider', (string) config('billing.provider'))
            ->latest('id')
            ->first();
    }

    /**
     * Resolve the target user from the event data. Returns null when absent/missing.
     *
     * @param  array<string,mixed>  $data
     */
    private function resolveUser(array $data): ?User
    {
        if (! isset($data['user_id'])) {
            return null;
        }

        return User::find($data['user_id']);
    }

    /**
     * Parse a timestamp value (ISO string or epoch seconds) into a Carbon instance.
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return Carbon::createFromTimestamp((int) $value);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a QueryException represents a UNIQUE constraint violation across the
     * SQLite (tests) and MySQL (runtime) drivers.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLState 23000 (integrity constraint) covers MySQL/SQLite unique violations.
        if (($e->getCode() === '23000') || ($e->getCode() === 23000)) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
