<?php

// Subscription plan definitions for the SaaS billing foundation (Phase 12).
// Plans are config-backed and env-tunable (mirrors config/uploads.php); there is
// no `plans` table. Each plan is keyed by its slug and carries display metadata,
// price (in minor currency units) and the usage limits enforced by BillingService.

return [
    'free' => [
        'slug' => 'free',
        'name' => 'Free',
        'price' => (int) env('PLAN_FREE_PRICE', 0),
        'billing_interval' => null,
        'max_active_events'    => (int) env('PLAN_FREE_MAX_ACTIVE_EVENTS', 1),
        'max_photos_per_event' => (int) env('PLAN_FREE_MAX_PHOTOS_PER_EVENT', 100),
        'max_storage_bytes'    => (int) env('PLAN_FREE_MAX_STORAGE_BYTES', 500 * 1024 * 1024), // 500MB
    ],
    'pro' => [
        'slug' => 'pro',
        'name' => 'Pro',
        'price' => (int) env('PLAN_PRO_PRICE', 49900), // minor units (e.g. centavos)
        'billing_interval' => env('PLAN_PRO_BILLING_INTERVAL', 'monthly'),
        'max_active_events'    => (int) env('PLAN_PRO_MAX_ACTIVE_EVENTS', 10),
        'max_photos_per_event' => (int) env('PLAN_PRO_MAX_PHOTOS_PER_EVENT', 5000),
        'max_storage_bytes'    => (int) env('PLAN_PRO_MAX_STORAGE_BYTES', 10 * 1024 * 1024 * 1024), // 10GB
    ],
];
