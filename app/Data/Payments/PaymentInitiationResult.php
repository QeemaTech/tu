<?php

namespace App\Data\Payments;

final class PaymentInitiationResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $transactionId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $clientSecret = null,
        public readonly array $payload = [],
        public readonly ?string $message = null,
    ) {}
}

