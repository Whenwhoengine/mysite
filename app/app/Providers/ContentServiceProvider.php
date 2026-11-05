<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\ContentQueryService;
use App\Domain\Content\Events\EventSignatureVerifier;
use App\Domain\Content\Http\UrlGuard;
use App\Domain\Content\Http\WordPressRestClient;
use App\Domain\Preview\PreviewTicketVerifier;
use App\Support\Html\Sanitizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * 內容整合（WordPress REST、快取、事件、預覽）的服務註冊。由內容工作包負責。
 */
class ContentServiceProvider extends ServiceProvider
{
    /** 搜尋每 IP 每分鐘上限（保護 WP 不被放大查詢；暫定值，見完工報告）。 */
    public const SEARCH_PER_MINUTE = 30;

    /** 預覽交換每 IP 每分鐘上限（暫定值，見完工報告）。 */
    public const PREVIEW_EXCHANGE_PER_MINUTE = 20;

    public function register(): void
    {
        $this->app->singleton(UrlGuard::class, fn (Application $app): UrlGuard => new UrlGuard((array) config('mysite.content.allowed_hosts', [])));

        $this->app->bind(WordPressRestClient::class, fn (Application $app): WordPressRestClient => new WordPressRestClient(
            $app->make(\Illuminate\Http\Client\Factory::class),
            $app->make(UrlGuard::class),
            (array) config('mysite.content'),
        ));

        $this->app->singleton(Sanitizer::class, fn (): Sanitizer => new Sanitizer(
            (string) config('app.url'),
            (string) config('mysite.wp_public_url'),
            (int) config('mysite.content.max_response_bytes', 2 * 1024 * 1024),
        ));

        $this->app->bind(ContentCache::class, fn (Application $app): ContentCache => new ContentCache(
            $app->make('cache')->store(),
            (array) config('mysite.content'),
        ));

        $this->app->bind(EventSignatureVerifier::class, fn (): EventSignatureVerifier => new EventSignatureVerifier(
            (string) config('mysite.content.event_key_id', ''),
            (string) config('mysite.content.event_hmac_key', ''),
            (int) config('mysite.content.event_clock_skew', 300),
        ));

        $this->app->bind(PreviewTicketVerifier::class, fn (): PreviewTicketVerifier => new PreviewTicketVerifier(
            (string) config('mysite.preview.signing_key', ''),
            (string) config('mysite.preview.issuer', 'mysite-cms'),
            (string) config('mysite.preview.audience', 'mysite-app'),
            (int) config('mysite.preview.ttl', 600),
            (int) config('mysite.preview.max_ttl', 900),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('content-search', fn (Request $request): Limit => Limit::perMinute(self::SEARCH_PER_MINUTE)->by('search|'.$request->ip()));
        RateLimiter::for('preview-exchange', fn (Request $request): Limit => Limit::perMinute(self::PREVIEW_EXCHANGE_PER_MINUTE)->by('preview|'.$request->ip()));

        // 版型共用資料：品牌、主選單、頁尾選單。讀不到 WP 時改用內建後備，不讓整頁失敗。
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $data = $view->getData();
            $siteName = (string) config('mysite.site_name', 'mysite');
            $primary = $data['primaryMenu'] ?? null;
            $footer = $data['footerMenu'] ?? null;
            $site = $data['site'] ?? null;
            try {
                $query = $this->app->make(ContentQueryService::class);
                $preview = (bool) ($data['isPreview'] ?? false);
                $primary ??= $preview ? ContentQueryService::fallbackMenu() : $query->primaryMenu();
                $footer ??= $preview ? [] : $query->footerMenu();
                $site ??= $preview ? null : $query->site();
            } catch (Throwable) {
                $primary ??= ContentQueryService::fallbackMenu();
                $footer ??= [];
            }
            $view->with([
                'siteName' => $site?->name ?? $siteName,
                'primaryMenu' => $primary,
                'footerMenu' => $footer,
                'site' => $site,
                'commerceEnabled' => config('mysite.commerce.mode') === 'simulation',
            ]);
        });
    }
}
