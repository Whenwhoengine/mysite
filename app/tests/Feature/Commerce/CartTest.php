<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Cart\SessionCart;
use App\Domain\Commerce\Money;
use App\Models\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;
use Tests\Feature\Commerce\CommerceTestCase;

/** Session 購物車：數量／行數上限、篡改欄位無效、改價提示（COM-03）。 */
final class CartTest extends CommerceTestCase
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
    public function add_update_and_remove_items(): void
    {
        $variant = $this->variant('120.00');
        $this->addToCart($variant, 2)->assertRedirect('/cart')->assertSessionHas('status');
        $this->get('/cart')->assertOk()->assertSee('NT$240')->assertSee('模擬操作，不收款、不出貨');
        $this->patch('/cart/items/'.$variant->id, ['quantity' => 3])->assertRedirect('/cart');
        $this->get('/cart')->assertSee('NT$360');
        $this->delete('/cart/items/'.$variant->id)->assertRedirect('/cart');
        $this->get('/cart')->assertSee('購物車是空的');
    }

    #[Test]
    #[Group('COM-03')]
    public function client_supplied_price_discount_and_total_are_ignored(): void
    {
        $variant = $this->variant('500.00');
        $this->addToCart($variant, 1, ['price' => '0.01', 'unit_price' => '0', 'discount' => '100', 'total' => '0'])->assertRedirect('/cart');
        $line = session(SessionCart::KEY)['lines'][$variant->id];
        $this->assertSame(['q' => 1, 'p' => '500.00'], $line);
        $this->get('/cart')->assertSee('NT$500')->assertDontSee('NT$0.01');
    }

    #[Test]
    #[Group('COM-03')]
    public function quantity_must_be_a_bounded_integer(): void
    {
        $variant = $this->variant();
        $max = (int) config('mysite.commerce.max_line_quantity');
        foreach ([0, -1, $max + 1, '1.5', 'abc', ''] as $qty) {
            $this->addToCart($variant, 1, ['quantity' => $qty])->assertSessionHasErrors('quantity');
        }
        $this->assertNull(session(SessionCart::KEY));
        $this->addToCart($variant, $max)->assertRedirect('/cart');
        $this->addToCart($variant, 1)->assertSessionHasErrors('cart');
        $this->assertSame($max, session(SessionCart::KEY)['lines'][$variant->id]['q']);
        $this->patch('/cart/items/'.$variant->id, ['quantity' => $max + 1])->assertSessionHasErrors('quantity');
    }

    #[Test]
    #[Group('COM-03')]
    public function line_count_is_limited(): void
    {
        config(['mysite.commerce.max_cart_lines' => 2]);
        $this->addToCart($this->variant())->assertRedirect('/cart');
        $this->addToCart($this->variant())->assertRedirect('/cart');
        $this->addToCart($this->variant())->assertSessionHasErrors('cart');
        $this->assertCount(2, session(SessionCart::KEY)['lines']);
    }

    #[Test]
    #[Group('COM-03')]
    public function unpurchasable_variants_cannot_be_added(): void
    {
        $draft = $this->variant(productState: ['status' => ProductStatus::Draft]);
        $this->addToCart($draft)->assertSessionHasErrors('cart');
        $inactive = $this->variant();
        $inactive->forceFill(['is_active' => false])->save();
        $this->addToCart($inactive)->assertSessionHasErrors('cart');
        $this->post('/cart/items', ['variant_id' => 999999, 'quantity' => 1])->assertSessionHasErrors('cart');
        $this->assertNull(session(SessionCart::KEY));
        $this->get('/shop/'.$draft->product->slug)->assertNotFound();
    }

    #[Test]
    #[Group('COM-03')]
    public function price_change_is_announced_and_server_price_wins(): void
    {
        $variant = $this->variant('100.00');
        $this->addToCart($variant)->assertRedirect('/cart');
        $variant->forceFill(['price' => Money::fromDecimal('130.00')])->save();
        $this->get('/cart')->assertOk()->assertSee('價格已更新')->assertSee('NT$130');
        // 看過新價格後快照更新，不再提示
        $this->get('/cart')->assertOk()->assertDontSee('data-price-changed', false);
    }

    #[Test]
    #[Group('COM-03')]
    #[Group('OPS-12')]
    public function cart_pages_are_not_cacheable(): void
    {
        $response = $this->get('/cart');
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }
}
