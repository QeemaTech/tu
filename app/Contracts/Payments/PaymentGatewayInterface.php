<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentInitiationResult;
use App\Data\Payments\WebhookVerificationResult;
use App\Models\Order;

interface PaymentGatewayInterface
{
    /**
     * Machine-readable gateway code (e.g. paymob, stripe).
     */
    public function code(): string;

    /**
     * Start external checkout flow for an order.
     *
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, array $context = []): PaymentInitiationResult;

    /**
     * Verify and normalize webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult;
}

