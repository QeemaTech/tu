<?php

namespace App\Contracts\Payments;

interface PaymentGatewayResolverInterface
{
    public function resolve(string $paymentMethod): PaymentGatewayInterface;
}

