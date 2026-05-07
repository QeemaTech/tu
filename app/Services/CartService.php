<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(protected CartRepository $cartRepo) {}

    public function getCart(int $userId): Collection
    {
        return $this->cartRepo->getUserCartItems($userId);
    }

    public function addProduct(int $userId, Product $product, ?int $variantId = null): CartItem
    {
        return $this->cartRepo->addOrIncrement($userId, $product, $variantId);
    }

    public function updateProductQuantity(int $userId, Product $product, int $quantity, ?int $variantId = null): CartItem
    {
        return $this->cartRepo->updateQuantity($userId, $product, $quantity, $variantId);
    }

    public function removeProduct(int $userId, Product $product, ?int $variantId = null): int
    {
        return $this->cartRepo->removeItem($userId, $product, $variantId);
    }

    public function clearCart(int $userId): int
    {
        return $this->cartRepo->clearCart($userId);
    }

    /**
     * Calculate cart subtotal
     */
    public function calculateCartSubtotal(int $userId): float
    {
        $cartItems = $this->getCart($userId);
        $subtotal = 0.0;

        foreach ($cartItems as $item) {
            $product = $item->product;
            $variant = $item->variant;
            $quantity = (int) $item->quantity;

            $unitPrice = (float) ($variant?->price ?? $product->price);

            $discount = (float) ($product->discount ?? 0);
            $discountType = $product->discount_type;

            if ($discount > 0) {
                if ($discountType === 'percentage') {
                    $unitPrice -= ($unitPrice * $discount) / 100;
                } else {
                    $unitPrice = (float) max(0, $unitPrice - $discount);
                }
            }

            $subtotal += $quantity * $unitPrice;
        }

        return round($subtotal, 2);
    }

    /**
     * Validate and calculate coupon discount for cart
     *
     * @return array{coupon: Coupon, discount: float, subtotal: float}
     */
    public function applyCoupon(int $userId, string $code): array
    {
        $user = User::findOrFail($userId);
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon) {
            throw ValidationException::withMessages([
                'code' => [__('Coupon not found.')],
            ]);
        }

        if (! $coupon->isValid()) {
            throw ValidationException::withMessages([
                'code' => [__('Coupon is invalid or expired.')],
            ]);
        }

        $cartSubtotal = $this->calculateCartSubtotal($userId);
        $voucherMaxAmount = (float) setting('voucher_max_order_amount', 0);

        if ($voucherMaxAmount > 0 && $cartSubtotal >= $voucherMaxAmount) {
            throw ValidationException::withMessages([
                'code' => [__('Coupons can only be used for orders with subtotal less than :amount.', ['amount' => $voucherMaxAmount])],
            ]);
        }

        if ($cartSubtotal < (float) $coupon->min_cart_amount) {
            throw ValidationException::withMessages([
                'code' => [__('Cart total does not meet the minimum amount for this coupon.')],
            ]);
        }

        if ($coupon->usage_limit_per_user !== null && ! $coupon->isUsableByUser($user)) {
            throw ValidationException::withMessages([
                'code' => [__('Coupon usage limit reached.')],
            ]);
        }

        $discount = 0.0;
        if ($coupon->type === 'percentage') {
            $discount = round(($cartSubtotal * (float) $coupon->discount_value) / 100, 2);
        } else {
            $discount = min((float) $coupon->discount_value, $cartSubtotal);
        }

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'subtotal' => $cartSubtotal,
        ];
    }

    /**
     * Validate and calculate voucher discount for cart
     *
     * @return array{voucher: \App\Models\Voucher, discount: float, subtotal: float}
     */
    public function applyVoucher(int $userId, string $code): array
    {
        $user = User::findOrFail($userId);
        $voucher = \App\Models\Voucher::where('code', $code)
            ->where('user_id', $userId)
            ->first();

        if (! $voucher) {
            throw ValidationException::withMessages([
                'code' => [__('Voucher not found.')],
            ]);
        }

        if (! $voucher->isValid()) {
            throw ValidationException::withMessages([
                'code' => [__('Voucher is invalid or already used.')],
            ]);
        }

        $cartSubtotal = $this->calculateCartSubtotal($userId);
        $voucherMaxAmount = (float) setting('voucher_max_order_amount', 0);

        if ($voucherMaxAmount > 0 && round($cartSubtotal, 2) !== round($voucherMaxAmount, 2)) {
            throw ValidationException::withMessages([
                'code' => [__('Vouchers can only be used for orders with subtotal exactly equal to :amount.', ['amount' => $voucherMaxAmount])],
            ]);
        }

        $discountRate = (float) ($voucher->discount_rate ?? setting('voucher_discount_rate', 0));
        $discount = round(($cartSubtotal * $discountRate) / 100, 2);

        return [
            'voucher' => $voucher,
            'discount' => $discount,
            'subtotal' => $cartSubtotal,
        ];
    }
}
