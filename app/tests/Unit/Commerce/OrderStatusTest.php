<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Models\OrderStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** 訂單狀態機（COM-08）：只有 placed → cancelled。 */
final class OrderStatusTest extends TestCase
{
    #[Test]
    #[Group('COM-08')]
    public function only_placed_to_cancelled_is_legal(): void
    {
        $this->assertTrue(OrderStatus::Placed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Placed->canTransitionTo(OrderStatus::Placed));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Placed));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Cancelled));
        $this->assertSame(['placed', 'cancelled'], array_column(OrderStatus::cases(), 'value'));
    }

    #[Test]
    #[Group('COM-11')]
    public function there_is_no_paid_or_shipped_state(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertDoesNotMatchRegularExpression('/paid|ship|deliver|refund/i', $status->value);
        }
        $this->assertStringContainsString('模擬', OrderStatus::Placed->label());
    }

    #[Test]
    #[Group('COM-03')]
    public function checkout_rejection_messages_are_localized(): void
    {
        $e = new CheckoutRejected([1 => 'insufficient_stock', 2 => 'price_changed', 3 => 'insufficient_stock', 'x' => 'unknown']);
        $this->assertTrue($e->has('price_changed'));
        $this->assertFalse($e->has('unavailable'));
        $this->assertCount(3, $e->messages());
        $this->assertContains('部分商品庫存不足，整筆模擬訂單未成立。', $e->messages());
    }
}
