<?php

namespace App\Data\Payments;

final class WebhookVerificationResult
{
    /**
     * @param  array<string, mixed>  $normalized
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $eventType = null,
        public readonly ?string $externalTransactionId = null,
        public readonly ?string $paymentStatus = null,
        public readonly array $normalized = [],
        public readonly ?string $message = null,
    ) {}
}

