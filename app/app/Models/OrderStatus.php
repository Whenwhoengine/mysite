<?php

declare(strict_types=1);

namespace App\Models;

/**
 * 模擬訂單狀態機：placed → cancelled（唯一合法轉換）。沒有「已付款」「已出貨」狀態。
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Placed => $next === self::Cancelled,
            self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Placed => '已成立（模擬）',
            self::Cancelled => '已取消',
        };
    }
}
