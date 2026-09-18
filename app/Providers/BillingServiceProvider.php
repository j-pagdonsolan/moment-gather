<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Contracts\PaymentProviderInterface;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register any billing services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProviderInterface::class, function ($app) {
            return match (config('billing.provider')) {
                'fake' => new \App\Billing\Providers\FakePaymentProvider(),
                default => new \App\Billing\Providers\FakePaymentProvider(), // only fake ships this phase; real adapters slot in here later
            };
        });
    }

    /**
     * Bootstrap any billing services.
     */
    public function boot(): void
    {
        //
    }
}
