<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Billing\DTO\CheckoutSession;
use App\Billing\DTO\NormalizedWebhookEvent;
use App\Models\Subscription;
use App\Models\User;

interface PaymentProviderInterface
{
    public function createCheckoutSession(User $user, string $planSlug): CheckoutSession;

    public function verifyWebhookSignature(string $payload, ?string $signatureHeader, string $secret): bool;

    public function parseWebhook(string $payload): NormalizedWebhookEvent;

    public function cancelSubscription(Subscription $subscription): void;
}
