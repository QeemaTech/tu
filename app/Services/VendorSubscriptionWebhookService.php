<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Vendor;
use App\Models\VendorSubscription;
use App\Models\VendorSubscriptionPaymentRequest;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\VendorSubscriptionPaymentRequestRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VendorSubscriptionWebhookService
{
    public function __construct(
        protected PaymentTransactionRepository $paymentTransactionRepository,
        protected VendorSubscriptionPaymentRequestRepository $requestRepository
    ) {}

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{request_id:int,status:string,idempotent:bool,transaction_id:int|null}
     */
    public function processGatewayWebhook(string $gateway, array $normalized): array
    {
        return DB::transaction(function () use ($gateway, $normalized) {
            $externalTransactionId = (string) ($normalized['external_transaction_id'] ?? '');
            $externalOrderId = (string) ($normalized['external_order_id'] ?? '');
            $merchantOrderId = (string) ($normalized['merchant_order_id'] ?? '');
            $externalReference = (string) ($normalized['external_reference'] ?? '');
            $paymentStatus = (string) ($normalized['payment_status'] ?? 'pending');
            $currency = (string) ($normalized['currency'] ?? config('services.paymob.default_currency', 'EGP'));
            $amount = round(((float) ($normalized['amount_cents'] ?? 0)) / 100, 2);
            $rawPayload = is_array($normalized['raw'] ?? null) ? $normalized['raw'] : [];

            $paymentTransaction = $this->resolvePaymentTransaction($gateway, $externalTransactionId, $externalOrderId, $externalReference);
            $requestId = $this->resolveRequestId($paymentTransaction, $externalReference, $merchantOrderId);

            if (! $requestId) {
                throw new RuntimeException('Unable to resolve subscription payment request from webhook payload.');
            }

            $request = VendorSubscriptionPaymentRequest::query()->lockForUpdate()->find($requestId);
            if (! $request) {
                throw new RuntimeException('Subscription payment request not found.');
            }

            if (! $paymentTransaction) {
                $paymentTransaction = $this->paymentTransactionRepository->create([
                    'order_id' => null,
                    'gateway' => $gateway,
                    'payment_method' => $gateway,
                    'context' => 'vendor_subscription',
                    'context_id' => $request->id,
                    'status' => $paymentStatus,
                    'amount' => $amount > 0 ? $amount : (float) $request->amount,
                    'currency' => $currency,
                    'external_transaction_id' => $externalTransactionId !== '' ? $externalTransactionId : null,
                    'external_order_id' => $externalOrderId !== '' ? $externalOrderId : null,
                    'external_reference' => $externalReference !== '' ? $externalReference : $request->reference,
                    'webhook_payload' => $rawPayload,
                ]);
            }

            if ($paymentStatus === 'paid' && in_array((string) $request->status, ['paid', 'applied'], true)) {
                return [
                    'request_id' => (int) $request->id,
                    'status' => (string) $request->status,
                    'idempotent' => true,
                    'transaction_id' => (int) $paymentTransaction->id,
                ];
            }

            if ((string) $request->status === 'applied' && $paymentStatus !== 'paid') {
                return [
                    'request_id' => (int) $request->id,
                    'status' => (string) $request->status,
                    'idempotent' => true,
                    'transaction_id' => (int) $paymentTransaction->id,
                ];
            }

            $this->paymentTransactionRepository->update($paymentTransaction, [
                'status' => $paymentStatus,
                'context' => 'vendor_subscription',
                'context_id' => $request->id,
                'external_transaction_id' => $externalTransactionId !== '' ? $externalTransactionId : $paymentTransaction->external_transaction_id,
                'external_order_id' => $externalOrderId !== '' ? $externalOrderId : $paymentTransaction->external_order_id,
                'external_reference' => $externalReference !== '' ? $externalReference : $paymentTransaction->external_reference,
                'webhook_payload' => $rawPayload,
                'paid_at' => $paymentStatus === 'paid' ? now() : $paymentTransaction->paid_at,
                'failure_reason' => $paymentStatus === 'failed' ? 'Gateway payment failed.' : $paymentTransaction->failure_reason,
            ]);

            if ($paymentStatus === 'paid') {
                if ((string) $request->status !== 'applied') {
                    $this->applySubscriptionRequest($request);
                }

                $request->paid_at = $request->paid_at ?? now();
                $request->status = 'applied';
                $request->applied_at = $request->applied_at ?? now();
                $request->save();
            } elseif ($paymentStatus === 'failed' && (string) $request->status !== 'applied') {
                $request->status = 'failed';
                $request->failed_at = now();
                $request->save();
            }

            return [
                'request_id' => (int) $request->id,
                'status' => (string) $request->status,
                'idempotent' => false,
                'transaction_id' => (int) $paymentTransaction->id,
            ];
        });
    }

    private function resolvePaymentTransaction(string $gateway, string $externalTransactionId, string $externalOrderId, string $externalReference): ?PaymentTransaction
    {
        $transaction = null;
        if ($externalTransactionId !== '') {
            $transaction = $this->paymentTransactionRepository->findByGatewayAndExternalTransactionId($gateway, $externalTransactionId);
        }

        if (! $transaction && $externalOrderId !== '') {
            $transaction = $this->paymentTransactionRepository->findByExternalOrderId($gateway, $externalOrderId);
        }

        if (! $transaction && $externalReference !== '') {
            $transaction = $this->paymentTransactionRepository->findByExternalReference($gateway, $externalReference);
        }

        return $transaction;
    }

    private function resolveRequestId(?PaymentTransaction $transaction, string $externalReference, string $merchantOrderId): ?int
    {
        if ($transaction && $transaction->context === 'vendor_subscription' && $transaction->context_id) {
            return (int) $transaction->context_id;
        }

        if ($externalReference !== '' && preg_match('/subpay-(\d+)/i', $externalReference, $matches) === 1) {
            return (int) $matches[1];
        }

        if ($merchantOrderId !== '' && ctype_digit($merchantOrderId)) {
            return (int) $merchantOrderId;
        }

        if ($merchantOrderId !== '' && preg_match('/^(\d+)-/i', $merchantOrderId, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function applySubscriptionRequest(VendorSubscriptionPaymentRequest $request): void
    {
        if ((string) $request->status === 'applied') {
            return;
        }

        /** @var Vendor $vendor */
        $vendor = Vendor::query()->lockForUpdate()->findOrFail((int) $request->vendor_id);
        /** @var Plan $plan */
        $plan = Plan::query()->findOrFail((int) $request->plan_id);
        $immediate = (bool) $request->immediate;

        /** @var VendorSubscription|null $current */
        $current = null;
        if ($request->current_subscription_id) {
            $current = VendorSubscription::query()->lockForUpdate()->find((int) $request->current_subscription_id);
        }
        if (! $current) {
            $current = $vendor->activeSubscription();
        }

        if ($current && (int) $current->plan_id === (int) $plan->id) {
            $current->end_date = Carbon::parse($current->end_date)->addDays((int) $plan->duration_days)->toDateString();
            $current->status = 'active';
            $current->save();

            $vendor->update([
                'plan_id' => $plan->id,
                'subscription_start' => $current->start_date,
                'subscription_end' => $current->end_date,
            ]);

            return;
        }

        if ($current) {
            $isDowngrade = $this->isDowngrade($current->plan, $plan);

            if ($immediate) {
                $current->status = 'inactive';
                $current->save();

                $startDate = now();
                $endDate = now()->addDays((int) $plan->duration_days);

                if ($isDowngrade) {
                    if (! $plan->can_feature_products) {
                        $vendor->products()->featured()->update(['is_featured' => false]);
                    }

                    if ($plan->max_products_count) {
                        $excessCount = $vendor->products()->active()->count() - (int) $plan->max_products_count;
                        if ($excessCount > 0) {
                            $vendor->products()->active()
                                ->latest()
                                ->limit($excessCount)
                                ->update(['is_active' => false]);
                        }
                    }
                }
            } else {
                $startDate = Carbon::parse($current->end_date)->addDay();
                $endDate = (clone $startDate)->addDays((int) $plan->duration_days);
            }
        } else {
            $startDate = now();
            $endDate = now()->addDays((int) $plan->duration_days);
        }

        $vendor->update([
            'plan_id' => $plan->id,
            'subscription_start' => $startDate,
            'subscription_end' => $endDate,
        ]);

        $vendor->subscriptions()->create([
            'plan_id' => $plan->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'price' => $plan->getRawOriginal('price'),
            'status' => $immediate ? 'active' : 'inactive',
        ]);
    }

    private function isDowngrade(Plan $currentPlan, Plan $newPlan): bool
    {
        if ($newPlan->getRawOriginal('price') < $currentPlan->getRawOriginal('price')) {
            return true;
        }

        if ($currentPlan->can_feature_products && ! $newPlan->can_feature_products) {
            return true;
        }

        $currentMax = $currentPlan->max_products_count;
        $newMax = $newPlan->max_products_count;

        if ($currentMax === null && $newMax !== null) {
            return true;
        }

        if ($currentMax !== null && $newMax !== null && $currentMax > $newMax) {
            return true;
        }

        return false;
    }
}
