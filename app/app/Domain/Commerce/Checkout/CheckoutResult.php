<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Checkout;

use App\Models\Order;

final class CheckoutResult
{
    public function __construct(
        public readonly Order $order,
        /** true＝同一 Session 以同一冪等鍵重送，回傳既有訂單，未建立新單 */
        public readonly bool $replayed,
        /** 交易實際嘗試次數（死鎖重試時 > 1） */
        public readonly int $attempts,
    ) {}
}
