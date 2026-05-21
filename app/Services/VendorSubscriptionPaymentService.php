<?php

namespace App\Services;

use App\Contracts\Payments\PaymentGatewayResolverInterface;
use App\Data\Payments\PaymentInitiationResult;
use App\Models\Plan;
use App\Models\User;
use App\Models\VendorSubscriptionPaymentRequest;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\VendorSubscriptionPaymentRequestRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorSubscriptionPaymentService
{
    public function __construct(
        protected VendorSubscriptionPaymentRequestRepository $requestRepository,
        protected PaymentTransactionRepository $paymentTransactionRepository,
        protected PaymentGatewayResolverInterface $paymentGatewayResolver
    ) {}

    /**
     * Initiate checkout for vendor subscription payment.
     *
     * @return array{
     *   request: VendorSubscriptionPaymentRequest,
     *   result: PaymentInitiationResult,
     *   gateway: string,
     *   already_initiated: bool
     * }
     */
    public function initiateForUser(User $user, int $planId, bool $immediate = true, string $paymentMethod = 'paymob'): array
    {
        return DB::transaction(function () use ($user, $planId, $immediate, $paymentMethod) {
            if (! $user->hasRole('vendor') && ! $user->hasRole('vendor_employee')) {
                throw ValidationException::withMessages([
                    'user' => [__('User is not a vendor.')],
                ]);
            }

            $vendor = $user->vendor();
            if (! $vendor) {
                throw ValidationException::withMessages([
                    'vendor' => [__('Vendor not found.')],
                ]);
            }

            $vendor = \App\Models\Vendor::query()->lockForUpdate()->findOrFail($vendor->id);

            /** @var Plan|null $plan */
            $plan = Plan::query()->where('is_active', true)->find($planId);
            if (! $plan) {
                throw ValidationException::withMessages([
                    'plan_id' => [__('Plan not found or inactive.')],
                ]);
            }

            $current = $vendor->activeSubscription();
            $this->assertSwitchConstraints($vendor, $plan, $current);

            $gatewayCode = strtolower(trim($paymentMethod));
            $pendingRequest = $this->requestRepository->findLatestPendingByVendorForUpdate($vendor->id);
            if ($pendingRequest) {
                $activeTransaction = $this->paymentTransactionRepository->findLatestByContextAndGateway(
                    context: 'vendor_subscription',
                    contextId: (int) $pendingRequest->id,
                    gateway: $gatewayCode,
                    statuses: ['initiated', 'pending']
                );

                if ($activeTransaction) {
                    if (
                        (int) $pendingRequest->plan_id !== (int) $plan->id
                        || (bool) $pendingRequest->immediate !== $immediate
                    ) {
                        throw ValidationException::withMessages([
                            'plan_id' => [__('You already have a pending subscription payment. Complete or cancel it first.')],
                        ]);
                    }

                    return [
                        'request' => $pendingRequest,
                        'result' => new PaymentInitiationResult(
                            success: true,
                            status: 'already_initiated',
                            transactionId: $activeTransaction->external_transaction_id,
                            redirectUrl: is_array($activeTransaction->meta) ? ($activeTransaction->meta['redirect_url'] ?? null) : null,
                            clientSecret: null,
                            payload: [],
                            message: 'Payment already initiated for this subscription request.',
                        ),
                        'gateway' => $gatewayCode,
                        'already_initiated' => true,
                    ];
                }

                // No active transaction attached anymore; mark stale request as cancelled to unblock.
                $this->requestRepository->update($pendingRequest, [
                    'status' => 'cancelled',
                ]);
            }

            $amount = (float) $plan->getRawOriginal('price');
            $currency = (string) config('services.paymob.default_currency', 'EGP');
            $startDate = $this->resolveScheduledStartDate($current, $immediate);

            $request = $this->requestRepository->create([
                'vendor_id' => $vendor->id,
                'plan_id' => $plan->id,
                'current_subscription_id' => $current?->id,
                'immediate' => $immediate,
                'status' => 'pending_payment',
                'amount' => $amount,
                'currency' => $currency,
                'reference' => 'subpay-temp-'.uniqid(),
                'scheduled_start_date' => $startDate?->format('Y-m-d'),
                'meta' => [
                    'vendor_user_id' => $user->id,
                    'current_subscription_id' => $current?->id,
                ],
            ]);

            $request->reference = 'subpay-'.$request->id;
            $request->save();

            $gateway = $this->paymentGatewayResolver->resolve($paymentMethod);
            $paymentCarrierOrder = new \App\Models\Order(['total' => $amount]);
            $paymentCarrierOrder->id = $request->id;
            $paymentCarrierOrder->setRelation('user', $user);

            $result = $gateway->initiate(
                order: $paymentCarrierOrder, // lightweight compatible payload carrier
                context: [
                    'amount' => $amount,
                    'currency' => $currency,
                    'reference' => $request->reference,
                    'extras' => [
                        'subscription_payment_request_id' => (string) $request->id,
                        'vendor_id' => (string) $vendor->id,
                        'plan_id' => (string) $plan->id,
                    ],
                ]
            );

            $safePayload = $this->sanitizePaymentPayload($result->payload);

            $this->paymentTransactionRepository->create([
                'order_id' => null,
                'gateway' => $gateway->code(),
                'payment_method' => $paymentMethod,
                'context' => 'vendor_subscription',
                'context_id' => $request->id,
                'status' => $result->status,
                'amount' => $amount,
                'currency' => $currency,
                'external_transaction_id' => $result->transactionId,
                'external_order_id' => $this->extractExternalOrderIdFromInitiationPayload($result->payload),
                'external_reference' => $request->reference,
                'request_payload' => [
                    'payment_method' => $paymentMethod,
                    'reference' => $request->reference,
                    'vendor_id' => $vendor->id,
                    'plan_id' => $plan->id,
                ],
                'response_payload' => $safePayload,
                'failure_reason' => $result->success ? null : $result->message,
                'meta' => [
                    'redirect_url' => $result->redirectUrl,
                ],
            ]);

            return [
                'request' => $request->fresh(),
                'result' => new PaymentInitiationResult(
                    success: $result->success,
                    status: $result->status,
                    transactionId: $result->transactionId,
                    redirectUrl: $result->redirectUrl,
                    clientSecret: null,
                    payload: $safePayload,
                    message: $result->message,
                ),
                'gateway' => $gateway->code(),
                'already_initiated' => false,
            ];
        });
    }

    private function assertSwitchConstraints(\App\Models\Vendor $vendor, Plan $newPlan, ?\App\Models\VendorSubscription $current): void
    {
        if (! $current || ! $current->plan) {
            return;
        }

        $isDowngrade = $this->isDowngrade($current->plan, $newPlan);
        if (! $isDowngrade) {
            return;
        }

        if (! $newPlan->can_feature_products && $vendor->products()->featured()->count() > 0) {
            throw ValidationException::withMessages([
                'plan_id' => [__('You have featured products. Please remove featured status before downgrading.')],
            ]);
        }

        if ($newPlan->max_products_count && $vendor->products()->active()->count() > $newPlan->max_products_count) {
            throw ValidationException::withMessages([
                'plan_id' => [__('You exceed this plan maximum product count.')],
            ]);
        }
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

    private function resolveScheduledStartDate(?\App\Models\VendorSubscription $current, bool $immediate): ?\Carbon\Carbon
    {
        if (! $current || $immediate) {
            return now();
        }

        return \Carbon\Carbon::parse($current->end_date)->addDay();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePaymentPayload(array $payload): array
    {
        $sensitiveKeys = ['token', 'payment_key', 'client_secret', 'auth_token'];

        $walk = function ($value) use (&$walk, $sensitiveKeys) {
            if (! is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                    $value[$key] = '[REDACTED]';
                    continue;
                }
                $value[$key] = $walk($item);
            }

            return $value;
        };

        return $walk($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractExternalOrderIdFromInitiationPayload(array $payload): ?string
    {
        $orderId = data_get($payload, 'order.id')
            ?? data_get($payload, 'order_id')
            ?? data_get($payload, 'id');

        if ($orderId === null || $orderId === '') {
            return null;
        }

        return (string) $orderId;
    }
}
