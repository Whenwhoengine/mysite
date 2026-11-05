<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Support\Config\MysiteConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** GOV-01：設定目錄中的自訂鍵確實被驗證。 */
final class MysiteConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function mysite(array $override = []): array
    {
        $base = require __DIR__.'/../../../config/mysite.php';
        $base['environment'] = 'development';
        $base['wp_public_url'] = 'http://cms.mysite.localhost:8081';

        return array_replace_recursive($base, $override);
    }

    /** @return array<string, mixed> */
    private function framework(array $override = []): array
    {
        return array_replace([
            'app.debug' => true,
            'app.url' => 'http://mysite.localhost:8080',
            'session.secure' => false,
            'database.default' => 'mysql',
            'db.username' => 'mysite_app',
        ], $override);
    }

    #[Test]
    public function development_defaults_are_valid(): void
    {
        $this->assertSame([], MysiteConfig::validate($this->mysite(), $this->framework()));
    }

    #[Test]
    public function commerce_real_mode_is_rejected(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['commerce' => ['mode' => 'real']]), $this->framework());
        $this->assertContains('COMMERCE_MODE=real 不被允許：本案只有模擬商店', $errors);
    }

    #[Test]
    public function production_requires_https_secure_cookie_and_no_debug(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['environment' => 'production']), $this->framework());
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('APP_DEBUG', $joined);
        $this->assertStringContainsString('APP_URL 在 staging／production 必須使用 https', $joined);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $joined);
        $this->assertStringContainsString('CONTENT_EVENT_HMAC_KEY', $joined);
        $this->assertStringContainsString('DEPLOY_ENVIRONMENT', $joined);
    }

    #[Test]
    public function visibility_ttl_cannot_exceed_hard_limit(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['content' => ['visibility_ttl' => 61]]), $this->framework());
        $this->assertContains('CONTENT_VISIBILITY_TTL_SECONDS 必須介於 1 與 60', $errors);
    }

    #[Test]
    public function preview_ttl_cannot_exceed_900(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['preview' => ['ttl' => 901]]), $this->framework());
        $this->assertContains('PREVIEW_TTL_SECONDS 必須介於 60 與 900', $errors);
    }

    #[Test]
    public function content_api_host_must_be_allowlisted(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['content' => ['api_base_url' => 'http://169.254.169.254/wp-json/site-content/v1']]), $this->framework());
        $this->assertContains('CONTENT_API_BASE_URL 的 host 不在 CONTENT_ALLOWED_HOSTS', $errors);
    }

    #[Test]
    public function private_api_must_be_https(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['content' => ['private_api_base_url' => 'http://wp-web:8080/wp-json/site-content/v1']]), $this->framework());
        $this->assertContains('CONTENT_PRIVATE_API_BASE_URL 必須使用 https', $errors);
    }

    #[Test]
    public function app_and_cms_must_be_different_origins(): void
    {
        $errors = MysiteConfig::validate($this->mysite(['wp_public_url' => 'http://mysite.localhost:8080']), $this->framework());
        $this->assertContains('APP_URL 與 WP_PUBLIC_URL 不得為同一來源（cookie 與 canonical 必須分離）', $errors);
    }

    #[Test]
    public function root_database_user_is_rejected(): void
    {
        $errors = MysiteConfig::validate($this->mysite(), $this->framework(['db.username' => 'root']));
        $this->assertContains('Laravel 不得使用資料庫 root 帳號', $errors);
    }
}
