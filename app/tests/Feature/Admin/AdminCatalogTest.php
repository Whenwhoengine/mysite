<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\InventoryMutation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** 後臺商品／規格／庫存：驗證、只追加異動、稽核（M5、COM-02、COM-07、COM-10）。 */
final class AdminCatalogTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce('disabled'); // 商店關閉時仍可準備商品
    }

    private function productPayload(array $override = []): array
    {
        return array_merge([
            'slug' => 'new-mug', 'title' => '新馬克杯', 'description' => '描述', 'price' => '450.50',
            'status' => 'active', 'cover_url' => 'https://example.com/a.jpg', 'sku' => 'NEW-MUG-1', 'initial_stock' => 7,
        ], $override);
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_creates_product_with_default_variant_initial_stock_and_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/products/create')->assertOk();
        $response = $this->post('/admin/products', $this->productPayload());
        $product = Product::query()->where('slug', 'new-mug')->sole();
        $response->assertRedirect('/admin/products/'.$product->id.'/edit');

        $variant = $product->variants()->sole();
        $this->assertTrue($variant->is_default);
        $this->assertSame('450.50', $variant->price->toDecimal());
        $this->assertSame(7, $variant->stock);
        $this->assertSame([7], InventoryMutation::query()->where('product_variant_id', $variant->id)->pluck('delta')->all());
        $this->assertSame(InventoryMutation::REASON_INITIAL, InventoryMutation::query()->where('product_variant_id', $variant->id)->value('reason'));
        $this->assertTrue(AuditLog::query()->where(['action' => 'product.created', 'actor_user_id' => $admin->id])->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'inventory.initialized')->exists());
        $this->get('/admin/products')->assertOk()->assertSee('新馬克杯');
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertSee('NEW-MUG-1')->assertSee('初始庫存');
    }

    #[Test]
    #[Group('COM-02')]
    #[Group('COM-10')]
    public function invalid_money_slug_sku_and_cover_values_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $cases = [
            'price' => ['-1', '1e3', '1.005', '12,000', 'NaN', '10000000000'],
            'slug' => ['Bad Slug', 'UPPER', '-x', 'x--y'],
            'sku' => ['lower-sku', '-X', 'A B'],
            'cover_url' => ['http://insecure.example/a.jpg', 'javascript:alert(1)', '//evil.example/a.jpg', '"/><script>'],
            'status' => ['paid'],
            'initial_stock' => [-1, 'x'],
        ];
        foreach ($cases as $field => $values) {
            foreach ($values as $value) {
                $this->post('/admin/products', $this->productPayload([$field => $value]))->assertSessionHasErrors($field);
            }
        }
        $this->assertSame(0, Product::query()->count());
        $this->post('/admin/products', $this->productPayload())->assertSessionHasNoErrors();
        $this->post('/admin/products', $this->productPayload(['sku' => 'OTHER-1']))->assertSessionHasErrors('slug');
        $this->post('/admin/products', $this->productPayload(['slug' => 'other']))->assertSessionHasErrors('sku');
    }

    #[Test]
    #[Group('COM-07')]
    #[Group('COM-10')]
    public function updating_product_and_variant_prices_is_audited_and_does_not_touch_order_snapshots(): void
    {
        $this->setUpCommerce('simulation');
        $variant = $this->variant('100.00', 5);
        $this->placeOrder($variant, 1);
        $this->setUpCommerce('disabled');

        $admin = $this->admin();
        $this->actingAs($admin);
        $product = $variant->product;
        $this->put('/admin/products/'.$product->id, [
            'slug' => $product->slug, 'title' => '改過的標題', 'description' => str_repeat('長', 300), 'price' => '888.00', 'status' => 'archived', 'cover_url' => '/images/x.jpg',
        ])->assertRedirect('/admin/products/'.$product->id.'/edit');
        $this->put('/admin/variants/'.$variant->id, ['name' => '新規格名', 'price' => '777.77', 'is_active' => 0])->assertRedirect();

        $this->assertSame('archived', $product->refresh()->status->value);
        $this->assertSame('777.77', $variant->refresh()->price->toDecimal());
        $this->assertFalse($variant->is_active);
        $item = OrderItem::query()->sole();
        $this->assertSame('100.00', $item->unit_price->toDecimal());
        $this->assertSame('標準款', $item->variant_name);

        $audit = AuditLog::query()->where('action', 'product.updated')->sole();
        $this->assertSame('888.00', $audit->metadata['changes']['price']['to']);
        $this->assertSame('（300 字）', $audit->metadata['changes']['description']['to']);
        $this->assertTrue(AuditLog::query()->where('action', 'variant.updated')->exists());
        // 沒有變更就不寫稽核
        $count = AuditLog::query()->count();
        $this->put('/admin/variants/'.$variant->id, ['name' => '新規格名', 'price' => '777.77', 'is_active' => 0]);
        $this->assertSame($count, AuditLog::query()->count());
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_adds_variants_and_adjusts_stock_without_going_negative(): void
    {
        $admin = $this->admin();
        $variant = $this->variant('10.00', 3);
        $this->actingAs($admin);

        $this->post('/admin/products/'.$variant->product_id.'/variants', ['sku' => 'EXTRA-1', 'name' => '大', 'price' => '12.00', 'initial_stock' => 4])->assertRedirect();
        $extra = ProductVariant::query()->where('sku', 'EXTRA-1')->sole();
        $this->assertSame(4, $extra->stock);
        $this->assertFalse($extra->is_default);

        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 5, 'note' => '進貨'])->assertRedirect();
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => -8])->assertRedirect();
        $this->assertSame(0, $variant->refresh()->stock);
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => -1])->assertSessionHasErrors('delta');
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 0])->assertSessionHasErrors('delta');
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => '1.5'])->assertSessionHasErrors('delta');
        $this->assertSame(0, $variant->refresh()->stock);

        $deltas = InventoryMutation::query()->where('product_variant_id', $variant->id)->where('reason', InventoryMutation::REASON_MANUAL)->orderBy('id')->get();
        $this->assertSame([5, -8], $deltas->pluck('delta')->all());
        $this->assertSame([8, 0], $deltas->pluck('stock_after')->all());
        $this->assertSame($admin->id, $deltas->first()->actor_user_id);
        $this->assertSame(2, AuditLog::query()->where('action', 'inventory.adjusted')->count());
        $this->get('/admin')->assertOk()->assertSee('低庫存');
    }
}
