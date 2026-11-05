<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Cart;

use App\Domain\Commerce\Money;
use App\Models\ProductVariant;

/** 以 DB 現價重新計價後的一行（顯示用）。 */
final class PricedLine
{
    public function __construct(
        public readonly CartLine $line,
        public readonly ?ProductVariant $variant,
        public readonly ?Money $unitPrice,
        public readonly ?Money $lineTotal,
        public readonly bool $unavailable,
        public readonly bool $insufficientStock,
        public readonly bool $priceChanged,
    ) {}

    public function blocking(): bool
    {
        return $this->unavailable || $this->insufficientStock;
    }
}
