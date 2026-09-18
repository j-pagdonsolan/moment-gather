<?php

namespace App\Billing;

/**
 * Immutable value object describing a subscription plan.
 *
 * Plans are config-backed (config/plans.php) and env-tunable; there is no
 * `plans` table. Use {@see Plan::fromConfig()} to build a Plan from its slug,
 * which falls back to the Free plan when the slug is undefined (R1.7).
 */
final readonly class Plan
{
    public function __construct(
        public string $slug,
        public string $name,
        public int $price,
        public ?string $billingInterval,
        public int $maxActiveEvents,
        public int $maxPhotosPerEvent,
        public int $maxStorageBytes,
    ) {}

    public static function fromConfig(string $slug): self
    {
        $plans = config('plans', []);
        $data = $plans[$slug] ?? $plans['free'];

        return new self(
            slug: $data['slug'],
            name: $data['name'],
            price: (int) $data['price'],
            billingInterval: $data['billing_interval'] ?? null,
            maxActiveEvents: (int) $data['max_active_events'],
            maxPhotosPerEvent: (int) $data['max_photos_per_event'],
            maxStorageBytes: (int) $data['max_storage_bytes'],
        );
    }
}
