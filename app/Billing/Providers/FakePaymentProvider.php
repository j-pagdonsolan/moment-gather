<?php

declare(strict_types=1);

namespace App\Billing\Providers;

use App\Billing\Contracts\PaymentProviderInterface;
use App\Billing\DTO\CheckoutSession;
use App\Billing\DTO\NormalizedWebhookEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * FakePaymentProvider — the default, credential-free payment adapter.
 *
 * This adapter powers both local development and the entire automated test suite
 * deterministically. It signs its hosted-checkout tokens and its webhook payloads
 * with HMAC-SHA256 keyed by the webhook secret, so signature verification and
 * idempotency can be exercised end-to-end without any real provider.
 *
 * All provider-specific logic lives ONLY in this class. Secrets are never logged.
 */
final class FakePaymentProvider implements PaymentProviderInterface
{
    /**
     * Default HMAC key used when no webhook secret is configured. Keeps the fake
     * deterministic in local/test environments that do not set PAYMENT_WEBHOOK_SECRET.
     */
    private const DEFAULT_SECRET = 'fake-secret';

    /**
     * Build a signed hosted-checkout session pointing at the local fake checkout
     * route. The token carries the user id, target plan, and a random nonce, signed
     * with an HMAC so the fake checkout glue can trust which user/plan to activate.
     */
    public function createCheckoutSession(User $user, string $planSlug): CheckoutSession
    {
        $nonce = Str::uuid()->toString();

        $payload = [
            'user_id' => $user->id,
            'plan'    => $planSlug,
            'nonce'   => $nonce,
        ];

        $encoded   = $this->base64UrlEncode((string) json_encode($payload));
        $signature = $this->signPayload($encoded);
        $token     = $encoded.'.'.$signature;

        return new CheckoutSession(
            url: route('billing.fake-checkout', ['token' => $token]),
            providerRef: 'fake_sess_'.$nonce,
        );
    }

    /**
     * Verify an inbound webhook signature by recomputing HMAC-SHA256 over the raw
     * body with the given secret and comparing in constant time.
     */
    public function verifyWebhookSignature(string $payload, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Decode a raw JSON webhook body into a normalized event.
     *
     * @throws InvalidArgumentException when the required id/type keys are missing.
     */
    public function parseWebhook(string $payload): NormalizedWebhookEvent
    {
        /** @var array<string,mixed>|null $data */
        $data = json_decode($payload, true);

        if (! is_array($data) || ! isset($data['id'], $data['type'])) {
            throw new InvalidArgumentException('Webhook payload is missing required id/type keys.');
        }

        /** @var array<string,mixed> $eventData */
        $eventData = is_array($data['data'] ?? null) ? $data['data'] : [];

        return new NormalizedWebhookEvent(
            providerEventId: (string) $data['id'],
            type: (string) $data['type'],
            data: $eventData,
        );
    }

    /**
     * No-op for the fake provider. The authoritative local state change
     * (cancel_at_period_end, etc.) is performed by the caller/BillingService.
     */
    public function cancelSubscription(Subscription $subscription): void
    {
        // Intentionally a no-op: the fake provider holds no external state.
    }

    /**
     * Sign an arbitrary string with HMAC-SHA256 using the webhook secret.
     *
     * Exposed so tests and the fake-checkout glue can build valid signature headers.
     */
    public function signPayload(string $payload, ?string $secret = null): string
    {
        return hash_hmac('sha256', $payload, $secret ?? $this->secret());
    }

    /**
     * Build a deterministic-friendly JSON webhook payload. Callers may pass a fixed
     * $eventId to exercise idempotency; otherwise a unique fake event id is generated.
     *
     * @param  array<string,mixed>  $data
     */
    public function makeWebhookPayload(string $type, array $data, ?string $eventId = null): string
    {
        return (string) json_encode([
            'id'   => $eventId ?? ('fake_evt_'.Str::uuid()->toString()),
            'type' => $type,
            'data' => $data,
        ]);
    }

    /**
     * Verify and decode a hosted-checkout token produced by createCheckoutSession.
     *
     * Returns the decoded payload (user_id, plan, nonce) when the HMAC matches, or
     * null when the token is malformed or the signature does not verify. Used by the
     * fake-checkout glue to learn which user/plan to activate.
     *
     * @return array<string,mixed>|null
     */
    public function verifyCheckoutToken(string $token): ?array
    {
        if (! str_contains($token, '.')) {
            return null;
        }

        [$encoded, $signature] = explode('.', $token, 2);

        $expected = $this->signPayload($encoded);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($encoded);

        if ($decoded === null) {
            return null;
        }

        /** @var array<string,mixed>|null $payload */
        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Resolve the HMAC key: the configured webhook secret, or a fixed fallback.
     */
    private function secret(): string
    {
        return config('billing.webhook_secret') ?? self::DEFAULT_SECRET;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
