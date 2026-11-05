<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Exceptions\MoneyException;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** COM-02：Money 邊界、捨入、溢位、負數與非法輸入；運算不經 float。 */
final class MoneyTest extends TestCase
{
    #[Test]
    #[Group('COM-02')]
    public function parses_decimal_strings_into_minor_units(): void
    {
        $this->assertSame(0, Money::fromDecimal('0')->minor);
        $this->assertSame(1, Money::fromDecimal('0.01')->minor);
        $this->assertSame(10, Money::fromDecimal('0.1')->minor);
        $this->assertSame(123450, Money::fromDecimal('1234.50')->minor);
        $this->assertSame(500, Money::fromDecimal('005')->minor);
        $this->assertSame('1234.50', Money::fromDecimal('1234.5')->toDecimal());
        $this->assertSame('0.07', Money::fromMinor(7)->toDecimal());
    }

    #[Test]
    #[Group('COM-02')]
    public function accepts_decimal_12_2_upper_bound_and_rejects_beyond(): void
    {
        $max = Money::fromDecimal('9999999999.99');
        $this->assertSame(Money::MAX_MINOR, $max->minor);
        $this->assertSame('9999999999.99', $max->toDecimal());

        $this->expectException(MoneyException::class);
        Money::fromDecimal('10000000000.00');
    }

    #[Test]
    #[Group('COM-02')]
    public function rejects_minor_above_max(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromMinor(Money::MAX_MINOR + 1);
    }

    /** @return array<string, array{string}> */
    public static function invalidStrings(): array
    {
        return [
            'negative' => ['-1.00'],
            'three decimals' => ['1.005'],
            'exponent' => ['1e3'],
            'plus sign' => ['+5'],
            'empty' => [''],
            'spaces' => [' 10'],
            'thousand separator' => ['1,000'],
            'trailing dot' => ['10.'],
            'leading dot' => ['.5'],
            'hex' => ['0x10'],
            'nan' => ['NaN'],
            'full width digits' => ['１００'],
        ];
    }

    #[Test]
    #[Group('COM-02')]
    #[DataProvider('invalidStrings')]
    public function rejects_invalid_decimal_strings(string $value): void
    {
        $this->expectException(MoneyException::class);
        Money::fromDecimal($value);
    }

    #[Test]
    #[Group('COM-02')]
    public function rejects_float_input(): void
    {
        $this->expectException(MoneyException::class);
        Money::of(0.1 + 0.2);
    }

    #[Test]
    #[Group('COM-02')]
    public function rejects_negative_minor_and_unknown_types(): void
    {
        try {
            Money::fromMinor(-1);
            $this->fail('負數應被拒絕');
        } catch (MoneyException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(MoneyException::class);
        Money::of(['100']);
    }

    #[Test]
    #[Group('COM-02')]
    public function of_accepts_money_string_and_int_minor(): void
    {
        $m = Money::fromDecimal('12.34');
        $this->assertSame($m, Money::of($m));
        $this->assertTrue(Money::of('12.34')->equals($m));
        $this->assertTrue(Money::of(1234)->equals($m));
    }

    #[Test]
    #[Group('COM-02')]
    public function adds_subtracts_and_multiplies_exactly(): void
    {
        // 0.1 + 0.2 在 float 會是 0.30000000000000004；整數分運算必須剛好 0.30
        $sum = Money::fromDecimal('0.10')->add(Money::fromDecimal('0.20'));
        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertSame('0.10', $sum->subtract(Money::fromDecimal('0.20'))->toDecimal());
        $this->assertSame('3501.50', Money::fromDecimal('350.15')->multiply(10)->toDecimal());
        $this->assertSame('0.00', Money::fromDecimal('350.15')->multiply(0)->toDecimal());
    }

    #[Test]
    #[Group('COM-02')]
    public function detects_overflow_on_add_and_multiply(): void
    {
        $max = Money::fromMinor(Money::MAX_MINOR);
        try {
            $max->add(Money::fromMinor(1));
            $this->fail('相加溢位應被拒絕');
        } catch (MoneyException) {
            $this->addToAssertionCount(1);
        }
        try {
            Money::fromDecimal('5000000000.00')->multiply(2);
            $this->fail('相乘溢位應被拒絕');
        } catch (MoneyException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('9999999999.99', Money::fromMinor(Money::MAX_MINOR)->multiply(1)->toDecimal());
        $this->expectException(MoneyException::class);
        Money::fromMinor(1)->multiply(-1);
    }

    #[Test]
    #[Group('COM-02')]
    public function subtraction_below_zero_is_rejected(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromDecimal('1.00')->subtract(Money::fromDecimal('1.01'));
    }

    #[Test]
    #[Group('COM-02')]
    public function ratio_uses_integer_half_up_rounding(): void
    {
        $this->assertSame('5.01', Money::fromDecimal('10.01')->multiplyRatio(1, 2)->toDecimal()); // 5.005 → 5.01
        $this->assertSame('5.00', Money::fromDecimal('10.00')->multiplyRatio(1, 2)->toDecimal());
        $this->assertSame('3.33', Money::fromDecimal('10.00')->multiplyRatio(1, 3)->toDecimal()); // 3.333…
        $this->assertSame('6.67', Money::fromDecimal('10.00')->multiplyRatio(2, 3)->toDecimal()); // 6.666…
        $this->assertSame('0.01', Money::fromMinor(1)->multiplyRatio(1, 2)->toDecimal());        // 0.005 → 0.01
        $this->assertSame('0.00', Money::fromMinor(1)->multiplyRatio(1, 3)->toDecimal());
        $this->expectException(MoneyException::class);
        Money::fromMinor(1)->multiplyRatio(1, 0);
    }

    #[Test]
    #[Group('COM-02')]
    public function half_up_parsing_is_explicit(): void
    {
        $this->assertSame('10.01', Money::fromDecimalHalfUp('10.005')->toDecimal());
        $this->assertSame('10.00', Money::fromDecimalHalfUp('10.004999')->toDecimal());
        $this->assertSame('10.00', Money::fromDecimalHalfUp('10')->toDecimal());
        $this->assertSame('0.10', Money::fromDecimalHalfUp('0.095')->toDecimal());
        $this->expectException(MoneyException::class);
        Money::fromDecimalHalfUp('9999999999.995'); // 進位後超過上限
    }

    #[Test]
    #[Group('COM-02')]
    public function comparisons_and_currency_guard(): void
    {
        $a = Money::fromDecimal('1.00');
        $b = Money::fromDecimal('2.00');
        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThan($b));
        $this->assertSame(0, $a->compare(Money::fromMinor(100)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertFalse($a->equals(Money::fromDecimal('1.00', 'USD')));
        $this->assertSame('1.00', (string) $a);

        $this->expectException(MoneyException::class);
        $a->add(Money::fromDecimal('1.00', 'USD'));
    }

    #[Test]
    #[Group('COM-02')]
    public function rejects_invalid_currency_code(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromDecimal('1.00', 'twd');
    }

    #[Test]
    #[Group('COM-02')]
    public function formatting_is_separate_from_storage_precision(): void
    {
        $this->assertSame('NT$1,234', MoneyFormatter::format(Money::fromDecimal('1234.00')));
        $this->assertSame('NT$1,234.50', MoneyFormatter::format(Money::fromDecimal('1234.5')));
        $this->assertSame('NT$0', MoneyFormatter::format(Money::zero()));
        $this->assertSame('USD 3.00', MoneyFormatter::format(Money::fromDecimal('3', 'USD')));
        // 顯示省略小數不影響儲存精度
        $this->assertSame('1234.00', Money::fromDecimal('1234')->toDecimal());
    }
}
