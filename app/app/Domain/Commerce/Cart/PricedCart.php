<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Cart;

use App\Domain\Commerce\Money;

final class PricedCart
{
    /** @param array<int, PricedLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly Money $subtotal,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasBlockingIssues(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->blocking()) {
                return true;
            }
        }

        return false;
    }

    public function hasPriceChanges(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->priceChanged) {
                return true;
            }
        }

        return false;
    }

    public function itemCount(): int
    {
        return array_sum(array_map(static fn (PricedLine $l) => $l->line->quantity, $this->lines));
    }
}
