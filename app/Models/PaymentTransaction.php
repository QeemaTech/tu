<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'gateway',
        'payment_method',
        'context',
        'context_id',
        'status',
        'amount',
        'currency',
        'external_transaction_id',
        'external_order_id',
        'external_reference',
        'request_payload',
        'response_payload',
        'webhook_payload',
        'failure_reason',
        'paid_at',
        'refunded_at',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'webhook_payload' => 'array',
        'meta' => 'array',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function subscriptionPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(VendorSubscriptionPaymentRequest::class, 'context_id')
            ->where('context', 'vendor_subscription');
    }
}
