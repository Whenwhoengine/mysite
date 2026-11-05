<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Cart\SessionCart;
use App\Domain\Commerce\Exceptions\CartLimitException;
use App\Domain\Commerce\Money;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;

/** Session 購物車計算、上限與快照（COM-03）。 */
final class SessionCartTest extends CommerceTestCase
{
    private Store $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Store('test', new ArraySessionHandler(120));
    }

    private function cart(int $maxLines = 3, int $maxQty = 5, int $days = 7): SessionCart
    {
        return new SessionCart($this->session, $maxLines, $maxQty, $days);
    }

    #[Test]
    #[Group('COM-03')]
    public function stores_only_variant_quantity_and_price_snapshot(): void
    {
        $cart = $this->cart();
        $cart->add(7, 2, Money::fromDecimal('99.50'));
        $cart->add(7, 1, Money::fromDecimal('120.00')); // 已存在：累加數量，保留原快照
        $raw = $this->session->get(SessionCart::KEY);
        $this->assertSame(['q' => 3, 'p' => '99.50'], $raw['lines'][7]);
        $this->assertSame([7], array_keys($cart->lines()));
        $this->assertSame(3, $cart->count());
    }

    #[Test]
    #[Group('COM-03')]
    public function enforces_line_quantity_limit(): void
    {
        $cart = $this->cart(maxQty: 5);
        $cart->add(1, 5, Money::fromDecimal('1.00'));
        try {
            $cart->add(1, 1, Money::fromDecimal('1.00'));
            $this->fail('超過單項上限應拒絕');
        } catch (CartLimitException) {
            $this->assertSame(5, $cart->lines()[1]->quantity);
        }
        foreach ([0, -1, 6] as $bad) {
            try {
                $cart->update(1, $bad);
                $this->fail("數量 {$bad} 應被拒絕");
            } catch (CartLimitException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[Group('COM-03')]
    public function enforces_line_count_limit(): void
    {
        $cart = $this->cart(maxLines: 2);
        $cart->add(1, 1, Money::fromDecimal('1.00'));
        $cart->add(2, 1, Money::fromDecimal('1.00'));
        $this->expectException(CartLimitException::class);
        $cart->add(3, 1, Money::fromDecimal('1.00'));
    }

    #[Test]
    #[Group('COM-03')]
    public function update_remove_and_acknowledge_price(): void
    {
        $cart = $this->cart();
        $cart->add(4, 1, Money::fromDecimal('10.00'));
        $this->assertTrue($cart->update(4, 3));
        $this->assertFalse($cart->update(99, 1));
        $cart->acknowledgePrice(4, Money::fromDecimal('12.00'));
        $this->assertSame('12.00', $cart->lines()[4]->priceSnapshot);
        $this->assertTrue($cart->remove(4));
        $this->assertFalse($cart->remove(4));
        $this->assertTrue($cart->isEmpty());
        $this->assertNull($this->session->get(SessionCart::KEY));
    }

    #[Test]
    #[Group('COM-03')]
    public function ignores_tampered_or_corrupt_session_lines(): void
    {
        $this->session->put(SessionCart::KEY, ['v' => 1, 'touched_at' => time(), 'lines' => [
            '5' => ['q' => 2, 'p' => '10.00'],
            '6' => ['q' => 999, 'p' => '0.01'],   // 超過上限
            '7' => ['q' => '2', 'p' => '10.00'],  // 非整數
            'x' => ['q' => 1, 'p' => '1.00'],     // 非法 ID
            '8' => 'garbage',
            '-1' => ['q' => 1, 'p' => '1.00'],
        ]]);
        $this->assertSame([5], array_keys($this->cart()->lines()));
    }

    #[Test]
    #[Group('COM-12')]
    public function cart_expires_after_retention_days_of_inactivity(): void
    {
        $cart = $this->cart(days: 7);
        $cart->add(1, 1, Money::fromDecimal('1.00'));
        Carbon::setTestNow(now()->addDays(8));
        try {
            $this->assertSame([], $cart->lines());
            $this->assertNull($this->session->get(SessionCart::KEY));
        } finally {
            Carbon::setTestNow();
        }
    }
}
