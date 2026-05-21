<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Data\Payments\PaymentInitiationResult;
use App\Data\Payments\WebhookVerificationResult;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymobPaymentService implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'paymob';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, array $context = []): PaymentInitiationResult
    {
        $apiKey = (string) config('services.paymob.api_key', '');
        $integrationId = (string) config('services.paymob.integration_id', '');

        if ($apiKey === '' || $integrationId === '') {
            return new PaymentInitiationResult(
                success: false,
                status: 'configuration_error',
                message: 'Paymob credentials are not fully configured.',
            );
        }

        $amountCents = (int) round((((float) ($context['amount'] ?? $order->total)) * 100));
        $currency = (string) ($context['currency'] ?? config('services.paymob.default_currency', 'EGP'));
        $requestPayload = $this->buildIntentionPayload($order, $context, $amountCents, $currency, $integrationId);

        try {
            $responseData = $this->requestIntention($apiKey, $requestPayload, $integrationId);
        } catch (\Throwable $e) {
            Log::error('Paymob initiation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return new PaymentInitiationResult(
                success: false,
                status: 'initiation_failed',
                payload: ['error' => $e->getMessage()],
                message: 'Unable to initiate Paymob payment.',
            );
        }

        $clientSecret = $this->readNested($responseData, [
            'client_secret',
            'intention_detail.client_secret',
            'data.client_secret',
            'payment_keys.client_secret',
        ]);

        $transactionId = (string) ($this->readNested($responseData, [
            'id',
            'intention_id',
            'data.id',
            'data.intention_id',
        ]) ?? '');

        $redirectUrl = $this->readNested($responseData, [
            'redirect_url',
            'payment_url',
            'data.redirect_url',
            'data.payment_url',
        ]);

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            $redirectUrl = $this->buildFallbackCheckoutUrl($clientSecret, $responseData);
        }

        return new PaymentInitiationResult(
            success: true,
            status: 'initiated',
            transactionId: $transactionId !== '' ? $transactionId : null,
            redirectUrl: $redirectUrl,
            clientSecret: is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null,
            payload: $responseData,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        $hmacSecret = (string) config('services.paymob.hmac_secret', '');
        if ($hmacSecret === '') {
            return new WebhookVerificationResult(valid: false, message: 'Missing PAYMOB_HMAC_SECRET configuration.');
        }

        $providedHmac = $this->extractProvidedHmac($payload, $headers);
        if ($providedHmac === null || $providedHmac === '') {
            return new WebhookVerificationResult(valid: false, message: 'Missing Paymob HMAC in callback payload/headers.');
        }

        $obj = isset($payload['obj']) && is_array($payload['obj']) ? $payload['obj'] : $payload;
        $calculatedHmac = $this->calculateTransactionHmac($obj, $hmacSecret);

        if (! hash_equals($calculatedHmac, strtolower($providedHmac))) {
            return new WebhookVerificationResult(valid: false, message: 'Invalid Paymob HMAC signature.');
        }

        $paymentStatus = $this->resolvePaymentStatus($obj);
        $externalTransactionId = $this->asNullableString($obj['id'] ?? null);
        $eventType = $this->asNullableString($payload['type'] ?? 'TRANSACTION');

        return new WebhookVerificationResult(
            valid: true,
            eventType: $eventType,
            externalTransactionId: $externalTransactionId,
            paymentStatus: $paymentStatus,
            normalized: [
                'gateway' => $this->code(),
                'external_transaction_id' => $externalTransactionId,
                'external_order_id' => $this->asNullableString($this->readNested($obj, ['order.id', 'order'])),
                'merchant_order_id' => $this->asNullableString($this->readNested($obj, [
                    'order.merchant_order_id',
                    'merchant_order_id',
                    'order_data.merchant_order_id',
                    'extras.order_id',
                ])),
                'external_reference' => $this->asNullableString($this->readNested($obj, [
                    'order.reference',
                    'order_data.reference',
                    'special_reference',
                    'reference',
                ])),
                'success' => (bool) ($obj['success'] ?? false),
                'pending' => (bool) ($obj['pending'] ?? false),
                'currency' => $obj['currency'] ?? null,
                'amount_cents' => $obj['amount_cents'] ?? null,
                'raw' => $payload,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildIntentionPayload(Order $order, array $context, int $amountCents, string $currency, string $integrationId): array
    {
        $billingData = $context['billing_data'] ?? [
            'first_name' => $order->user?->name ?? 'NA',
            'last_name' => 'NA',
            'email' => $order->user?->email ?? 'na@example.com',
            'phone_number' => $order->user?->phone ?? 'NA',
            'apartment' => 'NA',
            'floor' => 'NA',
            'street' => 'NA',
            'building' => 'NA',
            'shipping_method' => 'NA',
            'postal_code' => 'NA',
            'city' => 'NA',
            'country' => 'EG',
            'state' => 'NA',
        ];

        return [
            'amount' => $amountCents,
            'currency' => $currency,
            'merchant_order_id' => (string) $order->id,
            'payment_methods' => $context['payment_methods'] ?? [(int) $integrationId],
            'items' => $context['items'] ?? [],
            'billing_data' => $billingData,
            'special_reference' => (string) ($context['reference'] ?? ('order-'.$order->id)),
            'notification_url' => $context['notification_url'] ?? null,
            'redirection_url' => $context['redirection_url'] ?? null,
            'extras' => array_merge([
                'order_id' => (string) $order->id,
            ], (array) ($context['extras'] ?? [])),
        ];
    }

    private function generateAuthToken(string $apiKey): string
    {
        $endpoint = (string) config('services.paymob.auth_endpoint', '/api/auth/tokens');
        $response = Http::baseUrl((string) config('services.paymob.base_url'))
            ->timeout((int) config('services.paymob.timeout', 20))
            ->asJson()
            ->post($endpoint, ['api_key' => $apiKey]);

        if (! $response->successful()) {
            throw new \RuntimeException('Paymob auth failed: '.$response->body());
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            throw new \RuntimeException('Paymob auth token missing in response.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestIntention(string $apiKey, array $payload, string $integrationId): array
    {
        $endpoint = (string) config('services.paymob.intention_endpoint', '/v1/intention/');
        $timeout = (int) config('services.paymob.timeout', 20);
        $baseUrl = (string) config('services.paymob.base_url');
        $errors = [];

        // Strategy 1: Newer Paymob style for intention endpoint.
        $response = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Token '.$apiKey,
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post($endpoint, $payload);

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }
        $errors[] = 'token_api_key: '.$response->status().' '.$response->body();

        // Strategy 2: exchange API key for auth token and use bearer token.
        try {
            $authToken = $this->generateAuthToken($apiKey);
            $response = Http::baseUrl($baseUrl)
                ->timeout($timeout)
                ->withToken($authToken)
                ->asJson()
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $json = $response->json();

                return is_array($json) ? $json : [];
            }
            $errors[] = 'bearer_auth_token: '.$response->status().' '.$response->body();
        } catch (\Throwable $e) {
            $errors[] = 'auth_token_exchange: '.$e->getMessage();
        }

        // Strategy 3: bearer API key directly.
        $response = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->withToken($apiKey)
            ->asJson()
            ->post($endpoint, $payload);

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }
        $errors[] = 'bearer_api_key: '.$response->status().' '.$response->body();

        // Strategy 4: legacy Accept flow fallback for accounts not enabled for intention API.
        if ((bool) config('services.paymob.enable_legacy_fallback', true)) {
            try {
                return $this->requestLegacyCheckout($apiKey, $payload, $integrationId);
            } catch (\Throwable $e) {
                $errors[] = 'legacy_accept_flow: '.$e->getMessage();
            }
        }

        throw new \RuntimeException('Paymob intention request failed: '.implode(' | ', $errors));
    }

    /**
     * Legacy Accept flow:
     * 1) auth token
     * 2) create ecommerce order
     * 3) create payment key
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestLegacyCheckout(string $apiKey, array $payload, string $integrationId): array
    {
        $timeout = (int) config('services.paymob.timeout', 20);
        $baseUrl = (string) config('services.paymob.base_url');
        $authToken = $this->generateAuthToken($apiKey);

        $amountCents = (int) ($payload['amount'] ?? 0);
        $currency = (string) ($payload['currency'] ?? config('services.paymob.default_currency', 'EGP'));
        $merchantOrderId = (string) ($payload['merchant_order_id'] ?? '');
        $reference = (string) ($payload['special_reference'] ?? '');
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $billingData = is_array($payload['billing_data'] ?? null) ? $payload['billing_data'] : [];

        // Paymob legacy endpoint may reject repeated merchant_order_id with "duplicate".
        // Keep mapping info in reference/extras and send a unique merchant_order_id per attempt.
        $uniqueMerchantOrderId = $merchantOrderId !== ''
            ? $merchantOrderId.'-'.Str::lower(Str::random(8))
            : (string) Str::lower(Str::random(12));

        $orderResponse = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->asJson()
            ->post('/api/ecommerce/orders', [
                'auth_token' => $authToken,
                'delivery_needed' => false,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'items' => $items,
                'merchant_order_id' => $uniqueMerchantOrderId,
            ]);

        if (! $orderResponse->successful()) {
            throw new \RuntimeException('create_order_failed: '.$orderResponse->status().' '.$orderResponse->body());
        }

        $orderJson = $orderResponse->json();
        if (! is_array($orderJson) || ! isset($orderJson['id'])) {
            throw new \RuntimeException('create_order_invalid_response');
        }

        $orderId = (int) $orderJson['id'];

        $paymentKeyResponse = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->asJson()
            ->post('/api/acceptance/payment_keys', [
                'auth_token' => $authToken,
                'amount_cents' => $amountCents,
                'expiration' => 3600,
                'order_id' => $orderId,
                'billing_data' => $billingData,
                'currency' => $currency,
                'integration_id' => (int) $integrationId,
                'lock_order_when_paid' => false,
            ]);

        if (! $paymentKeyResponse->successful()) {
            throw new \RuntimeException('payment_key_failed: '.$paymentKeyResponse->status().' '.$paymentKeyResponse->body());
        }

        $paymentKeyJson = $paymentKeyResponse->json();
        if (! is_array($paymentKeyJson) || empty($paymentKeyJson['token'])) {
            throw new \RuntimeException('payment_key_invalid_response');
        }

        $paymentToken = (string) $paymentKeyJson['token'];
        $iframeId = (string) config('services.paymob.iframe_id', '');
        $redirectUrl = $iframeId !== ''
            ? rtrim($baseUrl, '/').'/api/acceptance/iframes/'.$iframeId.'?payment_token='.urlencode($paymentToken)
            : null;

        return [
            'id' => (string) $orderId,
            'legacy' => true,
            'redirect_url' => $redirectUrl,
            'token' => $paymentToken,
            'payment_key' => $paymentToken,
            'merchant_order_id' => $uniqueMerchantOrderId,
            'special_reference' => $reference !== '' ? $reference : null,
            'order' => $orderJson,
            'payment_key_response' => $paymentKeyJson,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    private function extractProvidedHmac(array $payload, array $headers): ?string
    {
        $headerCandidates = [
            'hmac',
            'x-hmac',
            'x-paymob-hmac',
            'X-HMAC',
            'X-Paymob-HMAC',
        ];

        foreach ($headerCandidates as $header) {
            $value = $headers[$header] ?? null;
            if (is_string($value) && $value !== '') {
                return strtolower(trim($value));
            }
        }

        $payloadHmac = $payload['hmac'] ?? null;
        if (is_string($payloadHmac) && $payloadHmac !== '') {
            return strtolower(trim($payloadHmac));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $obj
     */
    private function calculateTransactionHmac(array $obj, string $hmacSecret): string
    {
        $fields = (array) config('services.paymob.transaction_hmac_fields', []);

        if ($fields === []) {
            throw new \RuntimeException('Missing Paymob transaction HMAC fields configuration.');
        }

        $concatenated = '';
        foreach ($fields as $field) {
            $value = $this->readNested($obj, [(string) $field]);
            $concatenated .= $this->stringifyHmacValue($value);
        }

        return hash_hmac('sha512', $concatenated, $hmacSecret);
    }

    /**
     * @param  mixed  $value
     */
    private function stringifyHmacValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $obj
     */
    private function resolvePaymentStatus(array $obj): string
    {
        $success = (bool) ($obj['success'] ?? false);
        $pending = (bool) ($obj['pending'] ?? false);
        $refunded = (bool) ($obj['is_refunded'] ?? false);
        $voided = (bool) ($obj['is_voided'] ?? false);

        if ($refunded || $voided) {
            return 'refunded';
        }

        if ($pending) {
            return 'pending';
        }

        return $success ? 'paid' : 'failed';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $paths
     * @return mixed
     */
    private function readNested(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = $data;
            $found = true;

            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $found = false;
                    break;
                }
            }

            if ($found) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $value
     */
    private function asNullableString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function buildFallbackCheckoutUrl(mixed $clientSecret, array $responseData): ?string
    {
        if (is_string($clientSecret) && $clientSecret !== '') {
            $publicKey = (string) config('services.paymob.public_key', '');
            if ($publicKey !== '') {
                return rtrim((string) config('services.paymob.base_url'), '/')
                    .'/unifiedcheckout/?publicKey='
                    .urlencode($publicKey)
                    .'&clientSecret='
                    .urlencode($clientSecret);
            }
        }

        $iframeId = (string) config('services.paymob.iframe_id', '');
        $token = $this->readNested($responseData, ['token', 'payment_key', 'data.token']);

        if ($iframeId !== '' && is_string($token) && $token !== '') {
            return rtrim((string) config('services.paymob.base_url'), '/')
                .'/api/acceptance/iframes/'
                .$iframeId
                .'?payment_token='
                .urlencode($token);
        }

        return null;
    }
}
