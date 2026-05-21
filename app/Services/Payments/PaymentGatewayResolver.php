<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Contracts\Payments\PaymentGatewayResolverInterface;
use InvalidArgumentException;

class PaymentGatewayResolver implements PaymentGatewayResolverInterface
{
    /**
     * @param  iterable<PaymentGatewayInterface>  $gateways
     */
    public function __construct(protected array $gateways = []) {}

    public function resolve(string $paymentMethod): PaymentGatewayInterface
    {
        $method = strtolower(trim($paymentMethod));

        foreach ($this->gateways as $gateway) {
            if (strtolower($gateway->code()) === $method) {
                return $gateway;
            }
        }

        throw new InvalidArgumentException(__('Unsupported payment method: :method', ['method' => $paymentMethod]));
    }
}
