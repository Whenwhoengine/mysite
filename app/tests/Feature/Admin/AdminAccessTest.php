<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** COM-10：後臺未授權者被拒、viewer 唯讀、無公開註冊、WP 帳號不通用。 */
final class AdminAccessTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce('disabled'); // 後臺在商店關閉時仍可用
    }

    /** @return array<string, array{string}> */
    public static function readPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'products' => ['/admin/products'],
            'orders' => ['/admin/orders'],
            'audit' => ['/admin/audit'],
            'users' => ['/admin/users'],
            'security' => ['/admin/security'],
        ];
    }

    #[Test]
    #[Group('COM-10')]
    #[DataProvider('readPages')]
    public function guests_are_redirected_to_admin_login(string $uri): void
    {
        $this->get($uri)->assertRedirect('/admin/login');
    }

    #[Test]
    #[Group('COM-10')]
    #[DataProvider('readPages')]
    public function accounts_without_admin_role_are_forbidden(string $uri): void
    {
        // 例如與 WordPress 帳號同 email 的 Laravel 使用者：沒有角色就沒有任何後臺權限
        $user = User::factory()->create(['email' => 'editor@example.com']);
        $this->actingAs($user)->get($uri)->assertForbidden();
    }

    #[Test]
    #[Group('COM-10')]
    #[DataProvider('readPages')]
    public function viewer_and_admin_can_read_admin_pages(string $uri): void
    {
        $this->actingAs($this->viewer())->get($uri)->assertOk()->assertSee('管理後臺');
        $this->actingAs($this->admin())->get($uri)->assertOk();
    }

    #[Test]
    #[Group('COM-10')]
    public function viewer_cannot_write_anything(): void
    {
        $viewer = $this->viewer();
        $variant = $this->variant('10.00', 5);
        $order = Order::factory()->create();
        $other = $this->admin();
        $this->actingAs($viewer);

        $this->get('/admin/products/create')->assertForbidden();
        $this->post('/admin/products', ['slug' => 'x', 'title' => 'x', 'price' => '1', 'status' => 'draft', 'sku' => 'X-1', 'initial_stock' => 1])->assertForbidden();
        $this->put('/admin/products/'.$variant->product_id, ['slug' => 'x', 'title' => 'x', 'price' => '1', 'status' => 'draft'])->assertForbidden();
        $this->post('/admin/products/'.$variant->product_id.'/variants', ['sku' => 'X-2', 'name' => 'x', 'price' => '1', 'initial_stock' => 0])->assertForbidden();
        $this->put('/admin/variants/'.$variant->id, ['name' => 'x', 'price' => '1', 'is_active' => 1])->assertForbidden();
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 5])->assertForbidden();
        $this->post('/admin/orders/'.$order->id.'/cancel')->assertForbidden();
        $this->get('/admin/orders/export')->assertForbidden();
        $this->post('/admin/users/'.$other->id.'/disable')->assertForbidden();

        $this->assertSame(5, $variant->refresh()->stock);
        $this->assertSame('placed', $order->refresh()->status->value);
        $this->assertNull($other->refresh()->disabled_at);
        // 唯讀頁面不顯示寫入表單
        $this->get('/admin/products/'.$variant->product_id.'/edit')->assertOk()->assertSee('唯讀')->assertDontSee('儲存商品');
    }

    #[Test]
    #[Group('COM-10')]
    public function guessing_admin_urls_does_not_bypass_authorization(): void
    {
        $order = Order::factory()->create();
        $this->get('/admin/orders/'.$order->id)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/orders/'.$order->id)->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/orders/999999')->assertNotFound();
    }

    #[Test]
    #[Group('COM-10')]
    public function there_is_no_public_registration(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
        $this->get('/admin/register')->assertNotFound();
        $this->post('/admin/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1'])->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    #[Group('COM-10')]
    public function disabled_user_is_logged_out_immediately(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $admin->forceFill(['disabled_at' => now()])->save();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    #[Test]
    #[Group('COM-10')]
    #[Group('OPS-12')]
    public function admin_pages_are_private_and_not_indexed(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $login = $this->get('/admin/login');
        $this->assertStringContainsString('no-store', (string) $login->headers->get('Cache-Control'));
    }

    #[Test]
    #[Group('COM-10')]
    public function staging_and_production_require_confirmed_two_factor(): void
    {
        $admin = $this->admin();
        $this->app['env'] = 'production';
        try {
            $this->actingAs($admin)->get('/admin/products')->assertRedirect('/admin/security');
            $this->get('/admin/security')->assertOk()->assertSee('開始設定 2FA');
            $admin->forceFill([
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(['a-b'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
            $this->get('/admin/products')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
