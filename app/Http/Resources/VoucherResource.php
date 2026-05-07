<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discount_rate' => (float) $this->discount_rate,
            'max_order_amount' => (float) $this->max_order_amount,
            'is_used' => (bool) $this->is_used,
            'used_at' => $this->used_at?->format('Y-m-d H:i:s'),
            'expires_at' => $this->expires_at?->format('Y-m-d H:i:s'),
            'is_expired' => $this->expires_at ? $this->expires_at->isPast() : false,
            'is_valid' => $this->isValid(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
