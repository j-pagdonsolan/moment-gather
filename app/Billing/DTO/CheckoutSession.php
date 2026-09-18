<?php

declare(strict_types=1);

namespace App\Billing\DTO;

final readonly class CheckoutSession
{
    public function __construct(
        public string $url,
        public string $providerRef,
    ) {
    }
}
