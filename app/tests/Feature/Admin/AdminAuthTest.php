<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Fortify\GenericPasswordResetLinkResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Commerce\CommerceTestCase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** 後臺登入、限流、2FA（含復原碼）、密碼重設與更新（COM-10、M7）。 */
final class AdminAuthTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce('disabled');
    }

    private function adminWithPassword(array $state = []): User
    {
        return User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => Hash::make(self::PASSWORD)] + $state);
    }

    /** 已完成 2FA 登記的管理員；回傳 [user, secret, recoveryCodes]。 */
    private function adminWithTwoFactor(): array
    {
        $user = $this->adminWithPassword();
        $secret = app(Google2FA::class)->generateSecretKey();
        $codes = ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd'];
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user, $secret, $codes];
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_can_log_in_and_out(): void
    {
        $user = $this->adminWithPassword();
        $this->get('/admin/login')->assertOk()->assertSee('登入')->assertDontSee('register');
        $this->post('/admin/login', ['email' => 'ADMIN@example.com', 'password' => self::PASSWORD])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->get('/admin/login')->assertRedirect('/admin');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login')->where('actor_user_id', $user->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.logout')->exists());
    }

    #[Test]
    #[Group('COM-10')]
    #[Group('DAT-02')]
    public function failed_logins_share_one_generic_message_and_audit_without_secrets(): void
    {
        $this->adminWithPassword();
        User::factory()->admin()->disabled()->create(['email' => 'off@example.com', 'password' => Hash::make(self::PASSWORD)]);
        User::factory()->create(['email' => 'norole@example.com', 'password' => Hash::make(self::PASSWORD)]);

        $messages = [];
        foreach ([['admin@example.com', 'wrong-password-x'], ['nobody@example.com', self::PASSWORD], ['off@example.com', self::PASSWORD], ['norole@example.com', self::PASSWORD]] as [$email, $password]) {
            $this->post('/admin/login', ['email' => $email, 'password' => $password])->assertSessionHasErrors('email');
            $messages[] = session('errors')->first('email');
            $this->assertGuest();
        }
        $this->assertCount(1, array_unique($messages), '失敗訊息不得透露帳號是否存在、停用或無角色');

        $failed = AuditLog::query()->where('action', 'auth.login_failed')->get();
        $this->assertCount(4, $failed);
        $dump = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString('admin@example.com', $dump);
        $this->assertNotEmpty($failed->first()->metadata['email_hash']);
    }

    #[Test]
    #[Group('COM-10')]
    public function login_is_rate_limited_with_rate_limited_error(): void
    {
        $this->adminWithPassword();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong-password-'.$i])->assertSessionHasErrors('email');
        }
        $this->postJson('/admin/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertStatus(429)->assertJson(['error' => 'RATE_LIMITED']);
        $this->assertGuest();
    }

    #[Test]
    #[Group('COM-10')]
    public function enabling_two_factor_requires_password_confirmation_and_a_valid_code(): void
    {
        $user = $this->adminWithPassword();
        $this->actingAs($user);
        $this->post('/admin/user/two-factor-authentication')->assertRedirect('/admin/user/confirm-password');
        $this->get('/admin/user/confirm-password')->assertOk()->assertSee('確認密碼');
        $this->post('/admin/user/confirm-password', ['password' => self::PASSWORD])->assertRedirect();

        $this->post('/admin/user/two-factor-authentication')->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $page = $this->get('/admin/security')->assertOk()->assertSee('data:image/svg+xml;base64,', false)->assertSee('確認啟用');
        $this->assertStringNotContainsString('<svg', (string) $page->getContent(), 'QR 以 data URI 呈現，不輸出未跳脫 HTML');

        $this->post('/admin/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');
        $this->assertNull($user->refresh()->two_factor_confirmed_at);

        $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
        $this->post('/admin/user/confirmed-two-factor-authentication', ['code' => $code])->assertRedirect();
        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
        $this->get('/admin/security')->assertSee('復原碼')->assertSee($user->recoveryCodes()[0]);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor_confirmed')->exists());

        $this->post('/admin/user/two-factor-recovery-codes')->assertRedirect();
        $this->delete('/admin/user/two-factor-authentication')->assertRedirect();
        $this->assertNull($user->refresh()->two_factor_secret);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor_disabled')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.recovery_codes_generated')->exists());
        $this->assertStringNotContainsString($code, json_encode(AuditLog::query()->get()->toArray()));
    }

    #[Test]
    #[Group('COM-10')]
    public function two_factor_login_with_totp_code(): void
    {
        [$user, $secret] = $this->adminWithTwoFactor();
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD])->assertRedirect('/admin/two-factor-challenge');
        $this->assertGuest();
        $this->get('/admin/two-factor-challenge')->assertOk()->assertSee('復原碼');
        $this->post('/admin/two-factor-challenge', ['code' => '000000'])->assertRedirect('/admin/two-factor-challenge');
        $this->assertGuest();
        $this->post('/admin/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    #[Group('COM-10')]
    public function recovery_code_logs_in_once_and_is_then_consumed(): void
    {
        [$user, , $codes] = $this->adminWithTwoFactor();
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);
        $this->post('/admin/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($codes[0], $user->refresh()->recoveryCodes());
        $this->assertContains($codes[1], $user->recoveryCodes());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.recovery_code_used')->exists());
        $this->assertStringNotContainsString($codes[0], json_encode(AuditLog::query()->get()->toArray()));

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);
        $this->post('/admin/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/admin/two-factor-challenge');
        $this->assertGuest();
    }

    #[Test]
    #[Group('COM-10')]
    #[Group('COM-11')]
    public function password_reset_goes_only_to_the_capture_mailer_and_does_not_enumerate(): void
    {
        Notification::fake();
        $user = $this->adminWithPassword();
        $this->get('/admin/forgot-password')->assertOk()->assertSee('郵件捕捉器');

        $this->post('/admin/forgot-password', ['email' => 'admin@example.com'])->assertSessionHas('status', GenericPasswordResetLinkResponse::MESSAGE);
        $this->post('/admin/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', GenericPasswordResetLinkResponse::MESSAGE)->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token, $user): bool {
            $token = $n->token;
            $mail = $n->toMail($user);
            $this->assertSame('管理後臺：重設密碼', $mail->subject);
            $this->assertStringContainsString('/admin/reset-password/', (string) $mail->actionUrl);

            return true;
        });
        $this->assertSame('log', config('mail.mailers.log.transport'));

        $this->get('/admin/reset-password/'.$token.'?email=admin@example.com')->assertOk()->assertSee('重設密碼');
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'admin@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'admin@example.com', 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1'])->assertRedirect('/admin/login');
        $this->assertTrue(Hash::check('brand-new-password-1', $user->refresh()->password));
        $this->assertTrue(AuditLog::query()->where('action', 'auth.password_reset')->exists());
    }

    #[Test]
    #[Group('COM-10')]
    public function disabled_accounts_cannot_reset_their_password(): void
    {
        $user = $this->adminWithPassword(['disabled_at' => now()]);
        $token = Password::broker()->createToken($user);
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'admin@example.com', 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->refresh()->password));
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_can_update_password_with_current_password(): void
    {
        $user = $this->adminWithPassword();
        $this->actingAs($user);
        $this->put('/admin/user/password', ['current_password' => 'wrong', 'password' => 'another-password-2', 'password_confirmation' => 'another-password-2'])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->put('/admin/user/password', ['current_password' => self::PASSWORD, 'password' => 'another-password-2', 'password_confirmation' => 'another-password-2'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('another-password-2', $user->refresh()->password));
        $this->assertTrue(AuditLog::query()->where('action', 'auth.password_updated')->exists());
        $this->get('/admin/security')->assertOk();
    }
}
