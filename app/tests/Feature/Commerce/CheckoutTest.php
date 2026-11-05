<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Checkout\CheckoutCommand;
use App\Domain\Commerce\Checkout\CheckoutService;
use App\Domain\Commerce\CommerceSession;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\Notifications\SimulatedOrderNotifier;
use App\Domain\Commerce\Notifications\SimulatedOrderPlacedMail;
use App\Models\InventoryMutation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** 模擬結帳（COM-03、COM-05、COM-06、COM-07、COM-09、COM-11）。併發部分見 tests/Integration/Commerce。 */
final class CheckoutTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce();
    }

    #[Test]
    #[Group('COM-03')]
    #[Group('COM-11')]
    public function full_checkout_creates_order_items_stock_and_mutations(): void
    {
        Mail::fake();
        $a = $this->variant('350.00', 5);
        $b = $this->variant('1280.50', 2);
        $this->addToCart($a, 2);
        $this->addToCart($b, 1);
        $key = $this->checkoutKey();
        [$response, $token] = $this->submitCheckout($key, ['buyer_code' => 'test-001']);
        $response->assertRedirect('/orders/'.$token)->assertSessionHas('status');

        $order = Order::query()->sole();
        $this->assertSame('1980.50', $order->total->toDecimal());
        $this->assertSame('1980.50', $order->subtotal->toDecimal());
        $this->assertSame(3, $order->item_count);
        $this->assertSame('TEST-001', $order->buyer_code);
        $this->assertSame('placed', $order->status->value);
        $this->assertSame(CommerceSession::tokenHash($token), $order->token_hash);
        $this->assertSame($key, $order->idempotency_key);
        $this->assertSame(3, $a->refresh()->stock);
        $this->assertSame(1, $b->refresh()->stock);
        $this->assertSame(2, OrderItem::query()->count());
        $this->assertEqualsCanonicalizing([-2, -1], InventoryMutation::query()->where('order_id', $order->id)->pluck('delta')->all());
        $this->assertNull(session('commerce.cart'), '成功後清空購物車');

        // token 明文不入 orders 表
        $row = (array) DB::table('orders')->where('id', $order->id)->first();
        $this->assertNotContains($token, array_map('strval', $row));

        $page = $this->get('/orders/'.$token);
        $page->assertOk()->assertSee('模擬操作，不收款、不出貨')->assertSee('未收款（模擬）')->assertSee($order->displayNumber());
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $page->headers->get('Referrer-Policy'));

        // 通知只在提交後寫入捕捉器，收件者為保留網域，不寄給訪客
        Mail::assertSent(SimulatedOrderPlacedMail::class, static fn ($mail) => $mail->hasTo(SimulatedOrderNotifier::CAPTURE_ADDRESS));
        Mail::assertSentCount(1);
    }

    #[Test]
    #[Group('COM-03')]
    public function tampered_price_total_discount_and_quantity_fields_do_not_affect_pricing(): void
    {
        $variant = $this->variant('999.00', 10);
        $this->addToCart($variant, 2);
        $key = $this->checkoutKey();
        [$response, $token] = $this->submitCheckout($key, [
            'price' => '1', 'unit_price' => '1', 'total' => '0.01', 'subtotal' => '0', 'discount' => '998',
            'quantity' => 1, 'quantities' => [$variant->id => 9], 'lines' => [['variant_id' => $variant->id, 'quantity' => 1, 'price' => '1']],
            'currency' => 'USD',
        ]);
        $response->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame('1998.00', $order->total->toDecimal());
        $this->assertSame('TWD', $order->currency);
        $this->assertSame(2, $order->items()->sole()->quantity);
        $this->assertSame(8, $variant->refresh()->stock);
    }

    #[Test]
    #[Group('COM-03')]
    public function server_rejects_checkout_when_price_changed_after_viewing(): void
    {
        $variant = $this->variant('100.00', 10);
        $this->addToCart($variant);
        $key = $this->checkoutKey();
        $variant->forceFill(['price' => Money::fromDecimal('150.00')])->save();

        [$response] = $this->submitCheckout($key);
        $response->assertRedirect('/checkout')->assertSessionHasErrors('checkout');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, $variant->refresh()->stock);

        // 重新確認後以新價格成立
        $this->get('/checkout')->assertSee('NT$150');
        [$response, $token] = $this->submitCheckout($this->checkoutKey());
        $this->assertNotNull($token);
        $this->assertSame('150.00', Order::query()->sole()->total->toDecimal());
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('COM-06')]
    public function insufficient_stock_rejects_whole_order_without_partial_writes(): void
    {
        $ok = $this->variant('10.00', 5);
        $short = $this->variant('20.00', 1);
        $this->addToCart($ok, 2);
        $this->addToCart($short, 1);
        $key = $this->checkoutKey();
        $short->forceFill(['stock' => 0])->save(); // 其他人先買走

        [$response] = $this->submitCheckout($key);
        $response->assertRedirect('/cart')->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, InventoryMutation::query()->whereNotNull('order_id')->count());
        $this->assertSame(5, $ok->refresh()->stock);
    }

    #[Test]
    #[Group('COM-03')]
    public function archived_product_is_rejected_at_checkout(): void
    {
        $variant = $this->variant('10.00', 5);
        $this->addToCart($variant);
        $key = $this->checkoutKey();
        $variant->product->forceFill(['status' => ProductStatus::Archived])->save();
        [$response] = $this->submitCheckout($key);
        $response->assertRedirect('/cart')->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    #[Group('COM-05')]
    public function resubmitting_the_same_idempotency_key_returns_the_same_order(): void
    {
        $variant = $this->variant('50.00', 10);
        $this->addToCart($variant, 2);
        $key = $this->checkoutKey();
        [, $first] = $this->submitCheckout($key);
        [$again, $second] = $this->submitCheckout($key); // 購物車已清空仍回到同一筆
        $again->assertRedirect('/orders/'.$first);
        $this->assertSame($first, $second);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(8, $variant->refresh()->stock);
        $this->get('/orders/'.$first)->assertOk();
    }

    #[Test]
    #[Group('COM-05')]
    #[Group('COM-09')]
    public function another_session_cannot_reuse_a_key_to_read_the_result(): void
    {
        $variant = $this->variant('50.00', 10);
        $this->addToCart($variant);
        $key = $this->checkoutKey();
        [, $token] = $this->submitCheckout($key);

        $this->switchToNewSession();
        $this->addToCart($variant);
        [$response, $leaked] = $this->submitCheckout($key);
        $response->assertRedirect('/checkout')->assertSessionHasErrors('checkout');
        $this->assertNull($leaked);
        $this->assertSame(1, Order::query()->count());
        $this->get('/orders/'.$token)->assertNotFound();
    }

    #[Test]
    #[Group('COM-05')]
    public function service_level_replay_and_cross_session_isolation(): void
    {
        $variant = $this->variant('10.00', 10);
        $service = app(CheckoutService::class);
        $key = bin2hex(random_bytes(16));
        $command = static fn (string $binding, string $token) => new CheckoutCommand($binding, $key, $token, [$variant->id => 1], [$variant->id => '10.00']);
        $bindingA = hash('sha256', 'a');
        $bindingB = hash('sha256', 'b');
        $tokenA = str_repeat('A', 43);

        $first = $service->placeOrder($command($bindingA, $tokenA));
        $replay = $service->placeOrder($command($bindingA, $tokenA));
        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->order->id, $replay->order->id);

        $other = $service->placeOrder($command($bindingB, str_repeat('B', 43)));
        $this->assertFalse($other->replayed, '他人 Session 同鍵不會拿到既有結果');
        $this->assertNotSame($first->order->id, $other->order->id);
        $this->assertSame(8, $variant->refresh()->stock);
    }

    #[Test]
    #[Group('COM-05')]
    public function unique_constraint_backs_idempotency_at_database_level(): void
    {
        $order = Order::factory()->create();
        $this->expectException(QueryException::class);
        Order::factory()->create([
            'idempotency_key' => $order->idempotency_key,
            'session_binding_hash' => $order->session_binding_hash,
        ]);
    }

    #[Test]
    #[Group('COM-09')]
    public function orders_require_token_and_the_same_session(): void
    {
        $variant = $this->variant('10.00', 10);
        $token = $this->placeOrder($variant);
        $this->get('/orders/'.$token)->assertOk();

        // 猜測 token、格式錯誤、長度不符一律 404
        $this->get('/orders/'.str_repeat('a', 43))->assertNotFound();
        $this->get('/orders/'.substr($token, 0, 42))->assertNotFound();
        $this->get('/orders/1')->assertNotFound();

        // 他人 Session 即使拿到正確 token 也看不到（不揭露個資或存在與否）
        $this->switchToNewSession();
        $this->get('/orders/'.$token)->assertNotFound()->assertDontSee('TEST');
        $this->post('/orders/'.$token.'/cancel')->assertNotFound();
        $this->assertSame('placed', Order::query()->sole()->status->value);
    }

    #[Test]
    #[Group('COM-07')]
    public function later_price_changes_do_not_rewrite_order_item_snapshots(): void
    {
        $variant = $this->variant('100.00', 10);
        $token = $this->placeOrder($variant, 2);
        $variant->forceFill(['price' => Money::fromDecimal('999.00'), 'name' => '改名後'])->save();
        $variant->product->forceFill(['title' => '新標題', 'price' => Money::fromDecimal('999.00')])->save();

        $item = OrderItem::query()->sole();
        $this->assertSame('100.00', $item->unit_price->toDecimal());
        $this->assertSame('200.00', $item->line_total->toDecimal());
        $this->assertSame('標準款', $item->variant_name);
        $this->assertNotSame('新標題', $item->product_title);
        $this->assertSame('200.00', Order::query()->sole()->total->toDecimal());
        $this->get('/orders/'.$token)->assertSee('NT$200')->assertDontSee('NT$999');

        $this->expectException(\LogicException::class);
        $item->forceFill(['unit_price' => Money::fromDecimal('1.00')])->save();
    }

    #[Test]
    #[Group('COM-06')]
    public function failure_inside_transaction_rolls_back_everything_and_sends_no_notice(): void
    {
        Mail::fake();
        $variant = $this->variant('10.00', 3);
        $service = new class(app(SimulatedOrderNotifier::class)) extends CheckoutService
        {
            protected function beforeCommit(Order $order): void
            {
                throw new RuntimeException('故意失敗');
            }
        };
        try {
            $service->placeOrder(new CheckoutCommand(hash('sha256', 'x'), bin2hex(random_bytes(16)), str_repeat('C', 43), [$variant->id => 2], [$variant->id => '10.00']));
            $this->fail('應拋出例外');
        } catch (RuntimeException $e) {
            $this->assertSame('故意失敗', $e->getMessage());
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, InventoryMutation::query()->whereNotNull('order_id')->count());
        $this->assertSame(3, $variant->refresh()->stock);
        Mail::assertNothingSent();
    }

    #[Test]
    #[Group('COM-03')]
    public function service_validates_lines_ticket_and_buyer_code(): void
    {
        $variant = $this->variant('10.00', 3);
        $service = app(CheckoutService::class);
        $binding = hash('sha256', 'v');
        $cases = [
            'empty_cart' => new CheckoutCommand($binding, bin2hex(random_bytes(16)), str_repeat('D', 43), [], []),
            'invalid_quantity' => new CheckoutCommand($binding, bin2hex(random_bytes(16)), str_repeat('D', 43), [$variant->id => 0], []),
            'invalid_ticket' => new CheckoutCommand($binding, 'short', str_repeat('D', 43), [$variant->id => 1], []),
            'invalid_buyer_code' => new CheckoutCommand($binding, bin2hex(random_bytes(16)), str_repeat('D', 43), [$variant->id => 1], [], '王小明 0912'),
            'unavailable' => new CheckoutCommand($binding, bin2hex(random_bytes(16)), str_repeat('D', 43), [999999 => 1], []),
        ];
        foreach ($cases as $reason => $command) {
            try {
                $service->placeOrder($command);
                $this->fail("{$reason} 應被拒絕");
            } catch (CheckoutRejected $e) {
                $this->assertTrue($e->has($reason), $reason);
            }
        }
        config(['mysite.commerce.max_cart_lines' => 1]);
        try {
            $service->placeOrder(new CheckoutCommand($binding, bin2hex(random_bytes(16)), str_repeat('D', 43), [$variant->id => 1, 999998 => 1], []));
            $this->fail('超過行數應被拒絕');
        } catch (CheckoutRejected $e) {
            $this->assertTrue($e->has('too_many_lines'));
        }
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    #[Group('COM-11')]
    public function checkout_form_has_no_card_address_or_phone_fields(): void
    {
        $this->addToCart($this->variant());
        $page = (string) $this->get('/checkout')->assertOk()->assertSee('模擬操作，不收款、不出貨')->getContent();
        $this->assertSame(1, preg_match('#<form[^>]*data-checkout-form[^>]*>(.*?)</form>#s', $page, $form));
        $html = $form[1];
        preg_match_all('/<(?:input|select|textarea)[^>]*name="([^"]+)"/', $html, $m);
        $this->assertEqualsCanonicalizing(['_token', 'idempotency_key', 'buyer_code'], $m[1]);
        $this->assertDoesNotMatchRegularExpression('/autocomplete="(cc-|tel|street|address|postal)/', $html);
    }

    #[Test]
    #[Group('COM-11')]
    public function no_notice_is_sent_when_mailer_is_not_a_capture_mailer(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->placeOrder($this->variant());
        Mail::assertNothingSent();
    }

    #[Test]
    #[Group('COM-03')]
    public function empty_cart_or_unknown_ticket_cannot_checkout(): void
    {
        $this->get('/checkout')->assertRedirect('/cart');
        $this->post('/checkout', ['idempotency_key' => 'not-hex'])->assertSessionHasErrors('idempotency_key');
        $this->post('/checkout', ['idempotency_key' => str_repeat('f', 32)])->assertRedirect('/checkout')->assertSessionHasErrors('checkout');
        $variant = $this->variant();
        $this->addToCart($variant);
        $key = $this->checkoutKey();
        $this->delete('/cart/items/'.$variant->id);
        [$response] = $this->submitCheckout($key);
        $response->assertRedirect('/cart')->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
    }
}
