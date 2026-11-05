<?php

declare(strict_types=1);

namespace Tests\Unit\Secrets;

use App\Support\Secrets\SecretFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * GAP-KEYRING-01／GOV-01：金鑰圈檔與 APP_PREVIOUS_KEYS_FILE 的 *_FILE 讀取規則
 * （金鑰圈格式錯誤拒絕啟動；舊 APP_KEY 檔可不存在或為空）。
 */
final class SecretFilesKeyRingTest extends TestCase
{
    private const K1 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const K2 = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    private string $dir;

    /** @var array<string, string|false> 測試前的環境值（結束時還原） */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mysite-keyring-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        foreach (['CONTENT_EVENT_HMAC_KEYRING_FILE', 'PREVIEW_SIGNING_KEYRING_FILE', 'APP_PREVIOUS_KEYS_FILE'] as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        foreach ($this->saved as $name => $value) {
            $this->setEnv($name, $value === false ? '' : $value);
        }
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function write(string $name, string $content, int $mode = 0640): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }

    private function appKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    #[Test]
    #[Group('GOV-01')]
    #[Group('OPS-01')]
    public function keyring_files_are_read_into_the_compact_form(): void
    {
        $path = $this->write('content_event_hmac_keyring', 'k1 '.self::K1." accept\nk2 ".self::K2." active\n");
        $this->setEnv('CONTENT_EVENT_HMAC_KEYRING_FILE', $path);

        $compact = SecretFiles::read('CONTENT_EVENT_HMAC_KEYRING');
        $this->assertSame('k1:'.self::K1.':accept,k2:'.self::K2.':active', $compact);
        $this->assertSame('k2', SecretFiles::readKeyRingPath('CONTENT_EVENT_HMAC_KEYRING', $path)->activeId());

        $this->setEnv('PREVIEW_SIGNING_KEYRING_FILE', '');
        $this->assertNull(SecretFiles::read('PREVIEW_SIGNING_KEYRING'), '未設定 _KEYRING_FILE 時沿用單一金鑰（回傳 null）');
    }

    #[Test]
    #[Group('GOV-01')]
    #[Group('OPS-01')]
    public function invalid_keyring_files_refuse_startup(): void
    {
        $cases = [
            '兩把 active' => ['k1 '.self::K1." active\nk2 ".self::K2." active\n", 0640, '恰好一把 active'],
            '沒有 active' => ['k1 '.self::K1." accept\n", 0640, '恰好一把 active'],
            '不是 64 hex' => ["k1 abc active\n", 0640, '64 字元'],
            '空檔' => ["\n", 0640, '內容為空'],
            '其他人可讀' => ['k1 '.self::K1." active\n", 0644, '權限過寬'],
        ];
        foreach ($cases as $label => [$content, $mode, $expected]) {
            $path = $this->write('ring-'.md5($label), $content, $mode);
            $this->setEnv('PREVIEW_SIGNING_KEYRING_FILE', $path);
            try {
                // bootstrap/secrets.php（exportToEnvironment）與容器 entrypoint（assertRequiredForEnvironment）都經由同一個讀取路徑
                SecretFiles::read('PREVIEW_SIGNING_KEYRING');
                $this->fail("{$label}：應拒絕啟動");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $label);
                $this->assertStringNotContainsString(self::K1, $e->getMessage(), '錯誤訊息不得含金鑰');
            }
        }
        $this->setEnv('PREVIEW_SIGNING_KEYRING_FILE', $this->dir.'/missing');
        $this->expectExceptionMessage('不存在');
        SecretFiles::read('PREVIEW_SIGNING_KEYRING');
    }

    #[Test]
    #[Group('GOV-01')]
    #[Group('OPS-01')]
    public function previous_app_keys_file_may_be_missing_or_empty(): void
    {
        $this->setEnv('APP_PREVIOUS_KEYS_FILE', $this->dir.'/app_previous_keys');
        $this->assertNull(SecretFiles::read('APP_PREVIOUS_KEYS'), '檔案不存在 → 沒有舊金鑰');

        $this->write('app_previous_keys', '');
        $this->assertNull(SecretFiles::read('APP_PREVIOUS_KEYS'), '空檔（retire 後）→ 沒有舊金鑰');
        $this->write('app_previous_keys', "\n \n");
        $this->assertNull(SecretFiles::read('APP_PREVIOUS_KEYS'), '只有空白 → 沒有舊金鑰');

        $old1 = $this->appKey();
        $old2 = $this->appKey();
        $this->write('app_previous_keys', $old1."\n");
        $this->assertSame($old1, SecretFiles::read('APP_PREVIOUS_KEYS'));
        $this->write('app_previous_keys', $old1."\n".$old2.",\n");
        $this->assertSame($old1.','.$old2, SecretFiles::read('APP_PREVIOUS_KEYS'), '多行或逗號分隔 → Laravel 的逗號分隔格式');
    }

    #[Test]
    #[Group('GOV-01')]
    #[Group('OPS-01')]
    public function invalid_previous_app_keys_are_rejected(): void
    {
        $this->setEnv('APP_PREVIOUS_KEYS_FILE', $this->dir.'/app_previous_keys');
        $cases = [
            '缺 base64: 前綴' => base64_encode(random_bytes(32)),
            '長度不是 32 位元組' => 'base64:'.base64_encode(random_bytes(16)),
            '不是 base64' => 'base64:***not-base64***',
        ];
        foreach ($cases as $label => $content) {
            $this->write('app_previous_keys', $content."\n");
            try {
                SecretFiles::read('APP_PREVIOUS_KEYS');
                $this->fail("{$label}：應拒絕");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('格式不合法', $e->getMessage(), $label);
                $this->assertStringNotContainsString($content, $e->getMessage(), '錯誤訊息不得含金鑰');
            }
        }
        $this->write('app_previous_keys', $this->appKey()."\n", 0644);
        $this->expectExceptionMessage('權限過寬');
        SecretFiles::read('APP_PREVIOUS_KEYS');
    }
}
