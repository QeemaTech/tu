<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\VendorSubscriptionPaymentRequestRepository;
use App\Services\OrderService;
use App\Services\Payments\PaymobPaymentService;
use App\Services\VendorSubscriptionWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymobPaymentService $paymobPaymentService,
        protected OrderService $orderService,
        protected VendorSubscriptionWebhookService $vendorSubscriptionWebhookService,
        protected PaymentTransactionRepository $paymentTransactionRepository,
        protected VendorSubscriptionPaymentRequestRepository $vendorSubscriptionPaymentRequestRepository
    ) {}

    public function paymob(Request $request): JsonResponse
    {
        Log::info('Paymob webhook received', [
            'content_type' => $request->header('Content-Type'),
            'has_hmac_header' => $request->hasHeader('hmac') || $request->hasHeader('x-hmac') || $request->hasHeader('x-paymob-hmac'),
            'ip' => $request->ip(),
        ]);

        $configuredWebhookSecret = (string) config('services.paymob.webhook_secret', '');
        if ($configuredWebhookSecret !== '') {
            $providedWebhookSecret = (string) ($request->header('X-Webhook-Secret')
                ?? $request->header('x-webhook-secret')
                ?? $request->query('secret', ''));

            if (! hash_equals($configuredWebhookSecret, $providedWebhookSecret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook secret.',
                ], 401);
            }
        }

        $payload = $request->all();
        if ($payload === []) {
            $raw = (string) $request->getContent();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        $headers = $this->flattenHeaders($request->headers->all());
        try {
            $verification = $this->paymobPaymentService->verifyWebhook($payload, $headers);
        } catch (\Throwable $e) {
            Log::error('Paymob webhook verification crash', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook verification failed unexpectedly.',
            ], 422);
        }

        if (! $verification->valid) {
            Log::warning('Paymob webhook rejected', [
                'reason' => $verification->message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $verification->message ?? 'Invalid webhook signature.',
            ], 422);
        }

        $normalized = array_merge($verification->normalized, [
            'payment_status' => (string) ($verification->paymentStatus ?? 'pending'),
        ]);

        $handler = $this->resolveHandler($normalized);

        try {
            $result = $handler === 'vendor_subscription'
                ? $this->vendorSubscriptionWebhookService->processGatewayWebhook('paymob', $normalized)
                : $this->orderService->processGatewayWebhook('paymob', $normalized);
        } catch (\Throwable $e) {
            Log::error('Paymob webhook processing failed', [
                'error' => $e->getMessage(),
                'normalized' => $normalized,
                'handler' => $handler,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('Paymob webhook processed', array_merge($result, ['handler' => $handler]));

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully.',
            'data' => $result,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $key => $values) {
            if (! is_array($values) || $values === []) {
                continue;
            }

            $flat[$key] = (string) $values[0];
            $flat[strtolower($key)] = (string) $values[0];
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function resolveHandler(array $normalized): string
    {
        $externalReference = (string) ($normalized['external_reference'] ?? '');
        if ($externalReference !== '' && preg_match('/^subpay-\d+$/i', $externalReference) === 1) {
            return 'vendor_subscription';
        }

        $gateway = (string) ($normalized['gateway'] ?? 'paymob');
        $externalTransactionId = (string) ($normalized['external_transaction_id'] ?? '');
        $externalOrderId = (string) ($normalized['external_order_id'] ?? '');
        $merchantOrderId = (string) ($normalized['merchant_order_id'] ?? '');

        if ($externalTransactionId !== '') {
            $transaction = $this->paymentTransactionRepository->findByGatewayAndExternalTransactionId($gateway, $externalTransactionId);
            if ($transaction && $transaction->context === 'vendor_subscription') {
                return 'vendor_subscription';
            }
        }

        if ($externalOrderId !== '') {
            $transaction = $this->paymentTransactionRepository->findByExternalOrderId($gateway, $externalOrderId);
            if ($transaction && $transaction->context === 'vendor_subscription') {
                return 'vendor_subscription';
            }
        }

        if ($externalReference !== '') {
            $transaction = $this->paymentTransactionRepository->findByExternalReference($gateway, $externalReference);
            if ($transaction && $transaction->context === 'vendor_subscription') {
                return 'vendor_subscription';
            }
        }

        // Legacy Paymob fallback: merchant_order_id can be "{requestId}-{random}".
        if ($merchantOrderId !== '' && preg_match('/^(\d+)-/i', $merchantOrderId, $matches) === 1) {
            $request = $this->vendorSubscriptionPaymentRequestRepository->findById((int) $matches[1]);
            if ($request) {
                return 'vendor_subscription';
            }
        }

        return 'order';
    }
}
