<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * COMMERCE_MODE 讀取入口。只有 simulation 會開放商店；real 不存在可啟用的實作。
 */
final class CommerceMode
{
    public const DISABLED = 'disabled';

    public const SIMULATION = 'simulation';

    public static function current(): string
    {
        return (string) config('mysite.commerce.mode', self::DISABLED);
    }

    public static function isSimulation(): bool
    {
        return self::current() === self::SIMULATION;
    }

    public static function isDisabled(): bool
    {
        return self::current() === self::DISABLED;
    }

    public static function currency(): string
    {
        return (string) config('mysite.commerce.currency', 'TWD');
    }

    public static function maxLineQuantity(): int
    {
        return (int) config('mysite.commerce.max_line_quantity', 10);
    }

    public static function maxCartLines(): int
    {
        return (int) config('mysite.commerce.max_cart_lines', 20);
    }
}
