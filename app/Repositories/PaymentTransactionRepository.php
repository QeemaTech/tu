<?php

namespace App\Repositories;

use App\Models\PaymentTransaction;

class PaymentTransactionRepository
{
    public function __construct(protected PaymentTransaction $model) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentTransaction
    {
        return $this->model->create($data);
    }

    public function findByGatewayAndExternalTransactionId(string $gateway, string $externalTransactionId): ?PaymentTransaction
    {
        return $this->model->newQuery()
            ->where('gateway', $gateway)
            ->where('external_transaction_id', $externalTransactionId)
            ->first();
    }

    public function findLatestByOrderAndGateway(int $orderId, string $gateway): ?PaymentTransaction
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->where('gateway', $gateway)
            ->latest('id')
            ->first();
    }

    public function findByExternalOrderId(string $gateway, string $externalOrderId): ?PaymentTransaction
    {
        return $this->model->newQuery()
            ->where('gateway', $gateway)
            ->where('external_order_id', $externalOrderId)
            ->latest('id')
            ->first();
    }

    public function findByExternalReference(string $gateway, string $externalReference): ?PaymentTransaction
    {
        return $this->model->newQuery()
            ->where('gateway', $gateway)
            ->where('external_reference', $externalReference)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentTransaction $transaction, array $data): bool
    {
        return $transaction->update($data);
    }
}
