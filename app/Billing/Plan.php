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

    /**
     * Build a Plan from config, merging in any per-field overrides from the
     * `plan_overrides` table. When no override row exists, or when an override
     * field is null, the corresponding config value is used — so this method
     * always falls back gracefully to the base config.
     *
     * Unlike {@see Plan::fromConfig()}, this method performs a single DB query
     * to load the override row; callers that need the raw config value
     * (e.g. for showing "source" badges) should use fromConfig() instead.
     *
     * @see \App\Models\PlanOverride
     */
    public static function fromEffectiveConfig(string $slug): self
    {
        // Step 1: base from config, falling back to free plan when slug is unknown
        $plans = config('plans', []);
        $data = $plans[$slug] ?? $plans['free'] ?? [];

        // Step 2: attempt to load a per-slug override row
        $override = \App\Models\PlanOverride::where('slug', $slug)->first();

        // Step 3: field-by-field null-coalesce — override wins only when non-null
        $price             = ($override && $override->price !== null)               ? $override->price               : (int) ($data['price'] ?? 0);
        $maxActiveEvents   = ($override && $override->max_active_events !== null)   ? $override->max_active_events   : (int) ($data['max_active_events'] ?? 0);
        $maxPhotosPerEvent = ($override && $override->max_photos_per_event !== null) ? $override->max_photos_per_event : (int) ($data['max_photos_per_event'] ?? 0);
        $maxStorageBytes   = ($override && $override->max_storage_bytes !== null)   ? $override->max_storage_bytes   : (int) ($data['max_storage_bytes'] ?? 0);

        // Step 4: construct and return the merged Plan
        return new self(
            slug: $data['slug'] ?? $slug,
            name: $data['name'] ?? $slug,
            price: (int) $price,
            billingInterval: $data['billing_interval'] ?? null,
            maxActiveEvents: (int) $maxActiveEvents,
            maxPhotosPerEvent: (int) $maxPhotosPerEvent,
            maxStorageBytes: (int) $maxStorageBytes,
        );
    }
}
