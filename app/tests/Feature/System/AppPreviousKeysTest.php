<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * APP_KEY 輪替（GAP-KEYRING-01、scripts/ops/rotate-secrets.sh --kind=app-key）：
 * APP_PREVIOUS_KEYS（逗號分隔，Laravel 原生 config app.previous_keys）讓舊 key 加密的資料在 add 之後仍可解密；
 * retire（清空舊 key）之後即無法解密。
 */
final class AppPreviousKeysTest extends TestCase
{
    private function key(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function rebuildEncrypter(string $current, array $previous): Encrypter
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');

        return $this->app->make('encrypter');
    }

    #[Test]
    #[Group('OPS-01')]
    public function previous_keys_decrypt_old_data_only_until_retire(): void
    {
        $old = $this->key();
        $new = $this->key();
        $ciphertext = $this->rebuildEncrypter($old, [])->encryptString('舊金鑰加密的資料');

        // add：新 APP_KEY 生效、舊 key 移入 previous_keys
        $added = $this->rebuildEncrypter($new, [$old]);
        $this->assertSame('舊金鑰加密的資料', $added->decryptString($ciphertext));
        $fresh = $added->encryptString('新資料');
        $this->assertSame('新資料', (new Encrypter(base64_decode(substr($new, 7)), 'AES-256-CBC'))->decryptString($fresh), '加密一律使用目前的 APP_KEY');

        // retire：舊 key 移除後，舊資料不再可解
        $retired = $this->rebuildEncrypter($new, []);
        $this->expectException(DecryptException::class);
        $retired->decryptString($ciphertext);
    }

    #[Test]
    #[Group('OPS-01')]
    #[Group('GOV-01')]
    public function app_previous_keys_env_is_parsed_into_the_native_config_key(): void
    {
        $a = $this->key();
        $b = $this->key();
        $saved = [$_ENV['APP_PREVIOUS_KEYS'] ?? null, $_SERVER['APP_PREVIOUS_KEYS'] ?? null, getenv('APP_PREVIOUS_KEYS')];
        try {
            foreach (["{$a},{$b}" => [$a, $b], '' => []] as $value => $expected) {
                $_ENV['APP_PREVIOUS_KEYS'] = $_SERVER['APP_PREVIOUS_KEYS'] = (string) $value;
                putenv('APP_PREVIOUS_KEYS='.$value);
                $config = require base_path('config/app.php');
                $this->assertSame($expected, array_values($config['previous_keys']), '逗號分隔 → app.previous_keys');
            }
        } finally {
            [$e, $s, $g] = $saved;
            if ($e === null) {
                unset($_ENV['APP_PREVIOUS_KEYS']);
            } else {
                $_ENV['APP_PREVIOUS_KEYS'] = $e;
            }
            if ($s === null) {
                unset($_SERVER['APP_PREVIOUS_KEYS']);
            } else {
                $_SERVER['APP_PREVIOUS_KEYS'] = $s;
            }
            putenv($g === false ? 'APP_PREVIOUS_KEYS' : 'APP_PREVIOUS_KEYS='.$g);
        }
    }
}
