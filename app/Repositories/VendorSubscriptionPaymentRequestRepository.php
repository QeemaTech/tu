<?php

namespace App\Repositories;

use App\Models\VendorSubscriptionPaymentRequest;

class VendorSubscriptionPaymentRequestRepository
{
    public function __construct(protected VendorSubscriptionPaymentRequest $model) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): VendorSubscriptionPaymentRequest
    {
        return $this->model->create($data);
    }

    public function findById(int $id): ?VendorSubscriptionPaymentRequest
    {
        return $this->model->newQuery()->find($id);
    }

    public function findByReference(string $reference): ?VendorSubscriptionPaymentRequest
    {
        return $this->model->newQuery()
            ->where('reference', $reference)
            ->first();
    }

    public function findLatestPendingByVendor(int $vendorId): ?VendorSubscriptionPaymentRequest
    {
        return $this->model->newQuery()
            ->where('vendor_id', $vendorId)
            ->where('status', 'pending_payment')
            ->latest('id')
            ->first();
    }

    public function findLatestPendingByVendorForUpdate(int $vendorId): ?VendorSubscriptionPaymentRequest
    {
        return $this->model->newQuery()
            ->where('vendor_id', $vendorId)
            ->where('status', 'pending_payment')
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(VendorSubscriptionPaymentRequest $request, array $data): bool
    {
        return $request->update($data);
    }
}
