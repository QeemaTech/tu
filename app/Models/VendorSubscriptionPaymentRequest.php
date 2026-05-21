<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorSubscriptionPaymentRequest extends Model
{
    protected $fillable = [
        'vendor_id',
        'plan_id',
        'current_subscription_id',
        'immediate',
        'status',
        'amount',
        'currency',
        'reference',
        'scheduled_start_date',
        'paid_at',
        'applied_at',
        'failed_at',
        'meta',
    ];

    protected $casts = [
        'immediate' => 'boolean',
        'amount' => 'decimal:2',
        'scheduled_start_date' => 'date',
        'paid_at' => 'datetime',
        'applied_at' => 'datetime',
        'failed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function currentSubscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'current_subscription_id');
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'context_id')
            ->where('context', 'vendor_subscription');
    }
}

