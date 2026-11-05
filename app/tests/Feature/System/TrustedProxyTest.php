<?php

namespace Tests\Feature\System;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * COM-10（限流以來源 IP 計）：主站只信任 gateway 固定位址轉送的 X-Forwarded-For（compose 的 TRUSTED_PROXIES＝GATEWAY_EDGE_IP）。
 * edge 子網內其他位址（例如代表主機本機或 docker-proxy 轉入的 Docker bridge 位址）帶來的 X-Forwarded-For 不得被採用，
 * 否則用戶端可偽造來源 IP，影響登入限流與稽核紀錄。
 *
 * 經由正式設定路徑驗證：設定 TRUSTED_PROXIES 環境變數，由 bootstrap/app.php 的 trustProxies() 在處理請求時讀取。
 */
#[Group('COM-10')]
final class TrustedProxyTest extends TestCase
{
    private const GATEWAY = '172.30.10.10';

    /** @var array{server:mixed,env:mixed,putenv:string|false} */
    private array $saved;

    protected function setUp(): void
    {
        $this->saved = [
            'server' => $_SERVER['TRUSTED_PROXIES'] ?? null,
            'env' => $_ENV['TRUSTED_PROXIES'] ?? null,
            'putenv' => getenv('TRUSTED_PROXIES'),
        ];
        $_SERVER['TRUSTED_PROXIES'] = self::GATEWAY;
        $_ENV['TRUSTED_PROXIES'] = self::GATEWAY;
        putenv('TRUSTED_PROXIES='.self::GATEWAY);
        parent::setUp();
        Route::get('/__test/client-ip', static fn (Request $request) => response($request->ip()));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($this->saved[$key] === null) {
                unset($GLOBALS[$global]['TRUSTED_PROXIES']);
            } else {
                $GLOBALS[$global]['TRUSTED_PROXIES'] = $this->saved[$key];
            }
        }
        putenv($this->saved['putenv'] === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$this->saved['putenv']);
    }

    public function test_gateway_forwarded_client_ip_is_used(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.77, 198.51.100.9'])
            ->get('/__test/client-ip')
            ->assertOk()
            ->assertSeeText('198.51.100.9');
    }

    public function test_other_edge_address_cannot_spoof_client_ip(): void
    {
        // 172.30.10.1 是 edge 網路的 Docker bridge 位址（主機本機、docker-proxy 轉入），也在 edge 子網內
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.10.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.77'])
            ->get('/__test/client-ip')
            ->assertOk()
            ->assertSeeText('172.30.10.1');
    }
}
