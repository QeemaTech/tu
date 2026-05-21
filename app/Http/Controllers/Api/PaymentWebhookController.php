<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderService;
use App\Services\Payments\PaymobPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymobPaymentService $paymobPaymentService,
        protected OrderService $orderService
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

        try {
            $result = $this->orderService->processGatewayWebhook('paymob', $normalized);
        } catch (\Throwable $e) {
            Log::error('Paymob webhook processing failed', [
                'error' => $e->getMessage(),
                'normalized' => $normalized,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('Paymob webhook processed', $result);

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
}
