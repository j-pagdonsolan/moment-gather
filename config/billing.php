<?php

// Billing / payment-provider configuration for the SaaS billing foundation (Phase 12).
// The provider defaults to the local `fake` adapter so development and the test suite
// run with no real credentials. `secret_key` and `webhook_secret` are read server-side
// only and are never exposed to the frontend or logged.

return [
    'provider'       => env('PAYMENT_PROVIDER', 'fake'),
    'currency'       => env('BILLING_CURRENCY', 'PHP'),
    'public_key'     => env('PAYMENT_PUBLIC_KEY'),
    'secret_key'     => env('PAYMENT_SECRET_KEY'),      // server-only, never sent to frontend
    'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),  // server-only
];
