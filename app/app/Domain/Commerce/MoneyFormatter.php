<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * 金額顯示格式（與運算分離）。TWD 整數金額不顯示小數，有角分時顯示兩位；儲存精度一律兩位。
 */
final class MoneyFormatter
{
    private const SYMBOLS = ['TWD' => 'NT$'];

    public static function format(Money $money): string
    {
        $symbol = self::SYMBOLS[$money->currency] ?? $money->currency.' ';
        $units = intdiv($money->minor, 100);
        $cents = $money->minor % 100;
        $text = number_format($units, 0, '.', ',');
        if ($cents !== 0 || $money->currency !== 'TWD') {
            $text .= '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }

        return $symbol.$text;
    }
}
