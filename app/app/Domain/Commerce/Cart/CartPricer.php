<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Cart;

use App\Domain\Commerce\CommerceMode;
use App\Domain\Commerce\Money;
use App\Models\ProductVariant;

/**
 * 以 DB 現價與上下架狀態重新計算購物車（COM-03）：Session 快照與前端提交的價格都不具權威。
 * 這裡是非鎖定讀取，只供顯示；真正成立訂單時 CheckoutService 會在交易內鎖列後再驗一次。
 */
final class CartPricer
{
    /** @param array<int, CartLine> $lines */
    public function price(array $lines): PricedCart
    {
        $currency = CommerceMode::currency();
        $variants = $lines === [] ? collect() : ProductVariant::query()
            ->with('product')
            ->whereIn('id', array_keys($lines))
            ->get()
            ->keyBy('id');

        $priced = [];
        $subtotal = Money::zero($currency);
        foreach ($lines as $id => $line) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($id);
            if ($variant === null || ! $variant->isPurchasable()) {
                $priced[$id] = new PricedLine($line, $variant, $variant?->price, null, true, false, false);

                continue;
            }
            $unit = $variant->price;
            $total = $unit->multiply($line->quantity);
            $subtotal = $subtotal->add($total);
            $priced[$id] = new PricedLine(
                $line,
                $variant,
                $unit,
                $total,
                false,
                $variant->stock < $line->quantity,
                $line->priceSnapshot !== $unit->toDecimal(),
            );
        }

        return new PricedCart($priced, $subtotal);
    }
}
