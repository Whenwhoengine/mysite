<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** 使用者停用／啟用：即時生效、留稽核、不可停用自己（COM-10）。 */
final class AdminUserTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce('disabled');
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_disables_and_reenables_another_account(): void
    {
        $admin = $this->admin();
        $target = $this->viewer();
        $target->forceFill(['password' => Hash::make('viewer-password-1')])->save();
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee($target->email)->assertSee('停用');
        $this->post('/admin/users/'.$target->id.'/disable')->assertRedirect('/admin/users');
        $this->assertTrue($target->refresh()->isDisabled());
        $this->assertFalse(DB::table('sessions')->where('id', 'target-session')->exists(), '停用後立即終止其 Session');
        $this->assertTrue(AuditLog::query()->where(['action' => 'user.disabled', 'actor_user_id' => $admin->id, 'target_id' => (string) $target->id])->exists());

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $target->email, 'password' => 'viewer-password-1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($admin)->post('/admin/users/'.$target->id.'/enable')->assertRedirect('/admin/users');
        $this->assertFalse($target->refresh()->isDisabled());
        $this->assertTrue(AuditLog::query()->where('action', 'user.enabled')->exists());
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_cannot_disable_themselves(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/disable')->assertForbidden();
        $this->assertFalse($admin->refresh()->isDisabled());
    }
}
