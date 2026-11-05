<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\Admin\CreateAdminUser;
use App\Console\Commands\Commerce\ApplyRetention;
use App\Console\Commands\Commerce\ReapplyDeletionLog;
use App\Domain\Audit\AuditLogger;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureCommerceEnabled;
use App\Http\Middleware\PrivateNoStore;
use App\Http\Middleware\RejectPaymentSemantics;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * 模擬商店與管理後臺的服務註冊（COMMERCE_MODE 旗標、policies）。由商店工作包負責。
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerMiddleware();
        $this->registerRateLimiters();
        $this->registerPolicies();
        $this->registerAuditListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdminUser::class, ApplyRetention::class, ReapplyDeletionLog::class]);
        }

        // 已登入者造訪 /admin/login 等 guest 頁面時導回後臺首頁（本站只有後臺使用登入）。
        RedirectIfAuthenticated::redirectUsing(static fn () => '/admin');
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('commerce.enabled', EnsureCommerceEnabled::class);
        $router->aliasMiddleware('commerce.no-payment', RejectPaymentSemantics::class);
        $router->aliasMiddleware('account.active', EnsureAccountActive::class);
        $router->aliasMiddleware('admin.access', EnsureAdminAccess::class);
        $router->aliasMiddleware('admin.2fa', RequireAdminTwoFactor::class);
        $router->aliasMiddleware('private.no-store', PrivateNoStore::class);
    }

    /** 限流（暫定值，待實測調整；超過時回 429 RATE_LIMITED）。 */
    private function registerRateLimiters(): void
    {
        $sessionKey = static fn (Request $request): string => ($request->hasSession() ? $request->session()->getId() : 'no-session').'|'.$request->ip();

        // 購物車寫入：每 Session＋IP 每分鐘 60 次
        RateLimiter::for('shop-cart', static fn (Request $request) => Limit::perMinute(60)->by('cart|'.$sessionKey($request)));
        // 結帳送出與訂單取消：每 Session＋IP 每分鐘 10 次；每 IP 每分鐘 30 次
        RateLimiter::for('shop-checkout', static fn (Request $request) => [
            Limit::perMinute(10)->by('checkout|'.$sessionKey($request)),
            Limit::perMinute(30)->by('checkout-ip|'.$request->ip()),
        ]);
        // 後臺寫入：每位使用者每分鐘 30 次
        RateLimiter::for('admin-write', static fn (Request $request) => Limit::perMinute(30)->by('admin-write|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // 後臺匯出：每位使用者每分鐘 5 次
        RateLimiter::for('admin-export', static fn (Request $request) => Limit::perMinute(5)->by('admin-export|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function registerPolicies(): void
    {
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
    }

    /** 登入與 2FA 相關事件寫入去秘密化稽核（不記錄密碼、驗證碼或 recovery code）。 */
    private function registerAuditListeners(): void
    {
        $audit = fn (): AuditLogger => $this->app->make(AuditLogger::class);

        Event::listen(Login::class, static fn (Login $e) => $audit()->record('auth.login', $e->user));
        Event::listen(Logout::class, static function (Logout $e) use ($audit): void {
            if ($e->user !== null) {
                $audit()->record('auth.logout', $e->user);
            }
        });
        Event::listen(Failed::class, static fn (Failed $e) => $audit()->record('auth.login_failed', null, null, null, [
            'email_hash' => AuditLogger::hashIdentifier((string) ($e->credentials['email'] ?? '')),
        ]));
        Event::listen(Lockout::class, static fn (Lockout $e) => $audit()->record('auth.lockout', null, null, null, [
            'email_hash' => AuditLogger::hashIdentifier((string) $e->request->input('email', '')),
        ]));
        Event::listen(TwoFactorAuthenticationEnabled::class, static fn ($e) => $audit()->record('auth.two_factor_enabled', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationConfirmed::class, static fn ($e) => $audit()->record('auth.two_factor_confirmed', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationDisabled::class, static fn ($e) => $audit()->record('auth.two_factor_disabled', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationFailed::class, static fn ($e) => $audit()->record('auth.two_factor_failed', null, $e->user));
        Event::listen(RecoveryCodesGenerated::class, static fn ($e) => $audit()->record('auth.recovery_codes_generated', $e->user, $e->user));
        Event::listen(RecoveryCodeReplaced::class, static fn (RecoveryCodeReplaced $e) => $audit()->record('auth.recovery_code_used', $e->user, $e->user));
    }
}
