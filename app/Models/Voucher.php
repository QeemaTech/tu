<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'code',
        'discount_rate',
        'max_order_amount',
        'is_used',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'discount_rate' => 'decimal:2',
        'max_order_amount' => 'decimal:2',
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggeringOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function isValid(): bool
    {
        return ! $this->is_used && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = 'VCH-'.strtoupper(bin2hex(random_bytes(4)));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
