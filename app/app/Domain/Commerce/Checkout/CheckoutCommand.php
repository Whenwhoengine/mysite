<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Checkout;

/**
 * 結帳輸入。只含伺服器端可信資料：Session 綁定、票券（冪等鍵＋token）、Session 購物車內容與可選測試代號。
 * 前端提交的價格、折扣、總額一律不進入此物件。
 */
final class CheckoutCommand
{
    /**
     * @param  array<int, int>  $quantities  variant ID => 數量
     * @param  array<int, string>  $priceSnapshots  variant ID => 加入購物車時的價格快照（只用來偵測改價）
     */
    public function __construct(
        public readonly string $sessionBindingHash,
        public readonly string $idempotencyKey,
        public readonly string $orderToken,
        public readonly array $quantities,
        public readonly array $priceSnapshots,
        public readonly ?string $buyerCode = null,
    ) {}
}
