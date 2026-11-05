<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Support\Config\MysiteConfig;
use App\Support\Secrets\KeyRing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** GOV-01／GAP-KEYRING-01：啟動時驗證金鑰圈設定；不合法即拒絕啟動，錯誤訊息不含金鑰。 */
final class MysiteConfigKeyRingTest extends TestCase
{
    private const K1 = '7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a';

    /** @return array<string, mixed> */
    private function mysite(?string $eventRing, ?string $previewRing): array
    {
        $base = require __DIR__.'/../../../config/mysite.php';
        $base['environment'] = 'development';
        $base['wp_public_url'] = 'http://cms.mysite.localhost:8081';
        $base['content']['event_keyring'] = $eventRing;
        $base['preview']['signing_keyring'] = $previewRing;

        return $base;
    }

    /** @return array<string, mixed> */
    private function framework(): array
    {
        return ['app.debug' => true, 'app.url' => 'http://mysite.localhost:8080', 'session.secure' => false, 'database.default' => 'mysql', 'db.username' => 'mysite_app'];
    }

    #[Test]
    #[Group('GOV-01')]
    #[Group('OPS-01')]
    public function valid_or_absent_keyrings_pass_and_invalid_ones_refuse_startup(): void
    {
        $valid = KeyRing::parse('X', 'k1 '.self::K1.' active')->toCompact();
        $this->assertSame([], MysiteConfig::validate($this->mysite(null, null), $this->framework()), '沒有金鑰圈（單一金鑰相容）');
        $this->assertSame([], MysiteConfig::validate($this->mysite($valid, $valid), $this->framework()));

        $errors = MysiteConfig::validate($this->mysite('k1:'.self::K1.':accept', 'k1:'.substr(self::K1, 2).':active'), $this->framework());
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('CONTENT_EVENT_HMAC_KEYRING 必須恰好一把 active', $joined);
        $this->assertStringContainsString('PREVIEW_SIGNING_KEYRING 第 1 筆的金鑰不是 64 字元小寫 hex', $joined);
        $this->assertStringNotContainsString(substr(self::K1, 2), $joined, '錯誤訊息不得含金鑰');
    }
}
