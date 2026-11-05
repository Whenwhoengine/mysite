<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Exceptions\MoneyException;

/**
 * 金額值物件（COM-02）。
 *
 * - 內部以「最小貨幣單位」（分，1/100）整數表示；DB 欄位一律 DECIMAL(12,2)。
 * - 只接受十進位字串或整數最小單位；任何 float 輸入一律拒絕（禁止先轉 float 再乘 100）。
 * - 不允許負數；上限為 DECIMAL(12,2) 可表示的 9999999999.99。
 * - 比例運算（如日後折扣）以整數運算並採 half-up（四捨五入，0.5 進位）。
 * - 顯示格式由 MoneyFormatter 負責，與運算分離。
 */
final class Money
{
    /** DECIMAL(12,2) 的最大值（以分表示）：9,999,999,999.99 */
    public const MAX_MINOR = 999_999_999_999;

    public const SCALE = 2;

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
        if ($minor < 0) {
            throw new MoneyException('金額不得為負數');
        }
        if ($minor > self::MAX_MINOR) {
            throw new MoneyException('金額超過 DECIMAL(12,2) 上限');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new MoneyException('幣別必須是三碼大寫 ISO 4217 代碼');
        }
    }

    public static function zero(string $currency = 'TWD'): self
    {
        return new self(0, $currency);
    }

    public static function fromMinor(int $minor, string $currency = 'TWD'): self
    {
        return new self($minor, $currency);
    }

    /**
     * 由十進位字串解析（例如 DB DECIMAL 回傳的 "1234.50"）。小數最多兩位；不接受指數、正負號、空白與千分位。
     */
    public static function fromDecimal(string $value, string $currency = 'TWD'): self
    {
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            throw new MoneyException('金額格式不合法（需為非負、最多 10 位整數與 2 位小數的十進位字串）');
        }

        return new self(self::combine($m[1], $m[2] ?? ''), $currency);
    }

    /**
     * 解析小數超過兩位的十進位字串，並以 half-up 捨入到分（例如 "10.005" → 10.01、"10.004" → 10.00）。
     * 只在明確需要捨入的情境使用；一般輸入請用 fromDecimal() 嚴格解析。
     */
    public static function fromDecimalHalfUp(string $value, string $currency = 'TWD'): self
    {
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,12}))?$/', $value, $m) !== 1) {
            throw new MoneyException('金額格式不合法');
        }
        $fraction = $m[2] ?? '';
        $cents = substr(str_pad($fraction, self::SCALE, '0'), 0, self::SCALE);
        $rest = substr($fraction, self::SCALE);
        $minor = self::combine($m[1], $cents);
        if ($rest !== '' && (int) $rest[0] >= 5) {
            $minor++;
        }

        return new self($minor, $currency);
    }

    /**
     * 通用入口：只接受 Money、十進位字串或 int（視為最小單位）；float 一律拒絕。
     */
    public static function of(mixed $value, string $currency = 'TWD'): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_float($value) => throw new MoneyException('禁止以 float 表示金額'),
            is_int($value) => self::fromMinor($value, $currency),
            is_string($value) => self::fromDecimal($value, $currency),
            default => throw new MoneyException('不支援的金額型別：'.get_debug_type($value)),
        };
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minor > self::MAX_MINOR - $this->minor) {
            throw new MoneyException('金額相加溢位');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minor > $this->minor) {
            throw new MoneyException('金額相減結果為負數');
        }

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** 乘以數量（非負整數）。 */
    public function multiply(int $quantity): self
    {
        if ($quantity < 0) {
            throw new MoneyException('數量不得為負數');
        }
        if ($quantity !== 0 && $this->minor > intdiv(self::MAX_MINOR, $quantity)) {
            throw new MoneyException('金額相乘溢位');
        }

        return new self($this->minor * $quantity, $this->currency);
    }

    /**
     * 比例運算：minor × numerator ÷ denominator，以整數運算並 half-up 捨入到分。
     * 例如 10.01 × 1/2 = 5.005 → 5.01。
     */
    public function multiplyRatio(int $numerator, int $denominator): self
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new MoneyException('比例必須為非負分子與正分母');
        }
        if ($numerator !== 0 && $this->minor > intdiv(PHP_INT_MAX - $denominator, $numerator)) {
            throw new MoneyException('比例運算溢位');
        }
        $product = $this->minor * $numerator;
        $quotient = intdiv($product, $denominator);
        $remainder = $product % $denominator;
        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return new self($quotient, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /** 儲存用固定兩位小數字串（例如 "1234.50"）。 */
    public function toDecimal(): string
    {
        $units = intdiv($this->minor, 100);
        $cents = $this->minor % 100;

        return $units.'.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new MoneyException('不同幣別不可直接運算');
        }
    }

    private static function combine(string $units, string $fraction): int
    {
        $units = ltrim($units, '0');
        $units = $units === '' ? '0' : $units;
        $cents = str_pad($fraction, self::SCALE, '0');

        // 10 位整數 × 100 + 2 位小數最多 999,999,999,999，不會超過 PHP_INT_MAX（64 位元）。
        return ((int) $units) * 100 + (int) $cents;
    }
}
