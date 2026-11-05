<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Cart;

/** Session 購物車的一行：variant ID、數量、加入時價格快照（只用於提示價格變動）。 */
final class CartLine
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $quantity,
        public readonly string $priceSnapshot,
    ) {}
}
