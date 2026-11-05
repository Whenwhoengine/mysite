<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\AuthenticateAdminUser;
use App\Actions\Fortify\GenericPasswordResetLinkResponse;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Laravel Fortify（管理後臺登入與 2FA）設定。由商店／後臺工作包負責。
 * 註冊功能已在 config/fortify.php 關閉；這裡不綁定 CreateNewUser，確保沒有任何公開建立帳號的路徑。
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 申請重設密碼一律回同一訊息（不洩露帳號是否存在）
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::authenticateUsing(new AuthenticateAdminUser);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(static fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(static fn () => view('auth.two-factor-challenge'));
        Fortify::requestPasswordResetLinkView(static fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(static fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::confirmPasswordView(static fn () => view('auth.confirm-password'));

        // 重設密碼信（正體中文）。正式 mail provider 核准前 MAIL_MAILER=log，只寫入捕捉器、不宣稱已寄達。
        ResetPassword::toMailUsing(static function ($notifiable, string $token): MailMessage {
            $url = route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
            $minutes = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage)
                ->subject('管理後臺：重設密碼')
                ->line('有人為此後臺帳號申請重設密碼。')
                ->action('重設密碼', $url)
                ->line("連結將在 {$minutes} 分鐘後失效。")
                ->line('若不是您本人申請，請忽略此信，並通知網站管理員。');
        });

        // 限流（暫定值）：登入每 email+IP 每分鐘 5 次；2FA 挑戰每個待驗證登入每分鐘 5 次；Fortify 整體每 IP 每分鐘 60 次。
        RateLimiter::for('login', static function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
        RateLimiter::for('two-factor', static function (Request $request) {
            return Limit::perMinute(5)->by('2fa|'.$request->session()->get('login.id', $request->ip()));
        });
        RateLimiter::for('fortify', static function (Request $request) {
            return Limit::perMinute(60)->by('fortify|'.$request->ip());
        });
    }
}
