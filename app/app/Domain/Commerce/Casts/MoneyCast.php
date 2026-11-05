<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Casts;

use App\Domain\Commerce\Exceptions\MoneyException;
use App\Domain\Commerce\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * DECIMAL(12,2) ⇄ Money。讀取時以字串解析（PDO MySQL 對 DECIMAL 回傳字串），寫入時只接受 Money 或十進位字串。
 *
 * @implements CastsAttributes<Money, Money|string>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }
        $currency = (string) ($attributes['currency'] ?? config('mysite.commerce.currency', 'TWD'));

        return Money::of((string) $value, $currency);
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        if (! $value instanceof Money && ! is_string($value)) {
            // int 容易與「元」混淆、float 會失真：寫入 DB 只接受 Money 或十進位字串。
            throw new MoneyException('金額欄位只接受 Money 或十進位字串');
        }
        $currency = (string) ($attributes['currency'] ?? config('mysite.commerce.currency', 'TWD'));

        return [$key => Money::of($value, $currency)->toDecimal()];
    }
}
