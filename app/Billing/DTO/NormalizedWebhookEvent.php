<?php

declare(strict_types=1);

namespace App\Billing\DTO;

final readonly class NormalizedWebhookEvent
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        public string $providerEventId,
        public string $type,
        public array $data,
    ) {
    }
}
