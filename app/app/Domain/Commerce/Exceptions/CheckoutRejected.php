<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use RuntimeException;

/**
 * 結帳被拒（整筆不成立，交易已回復）。problems 為 [variant_id => 原因代碼]，原因代碼：
 * empty_cart、too_many_lines、invalid_quantity、unavailable、insufficient_stock、price_changed、invalid_ticket、invalid_buyer_code。
 */
final class CheckoutRejected extends RuntimeException
{
    /** @param array<int|string, string> $problems */
    public function __construct(public readonly array $problems, string $message = '結帳未成立')
    {
        parent::__construct($message);
    }

    public function has(string $reason): bool
    {
        return in_array($reason, $this->problems, true);
    }

    /** @return list<string> */
    public function messages(): array
    {
        $map = [
            'empty_cart' => '購物車是空的。',
            'too_many_lines' => '購物車品項超過上限。',
            'invalid_quantity' => '商品數量不合法。',
            'unavailable' => '部分商品已下架或無法購買，請回購物車移除。',
            'insufficient_stock' => '部分商品庫存不足，整筆模擬訂單未成立。',
            'price_changed' => '部分商品價格已更新，請確認新價格後再送出。',
            'invalid_ticket' => '結帳頁已過期，請重新整理後再送出。',
            'invalid_buyer_code' => '測試代號格式不合法。',
        ];

        return array_values(array_unique(array_map(static fn (string $r) => $map[$r] ?? '結帳未成立。', array_values($this->problems))));
    }
}
