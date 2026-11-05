<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** INF-05：liveness 不碰 DB；readiness 在 DB 故障時失敗。 */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function liveness_is_ok_without_details(): void
    {
        $this->get('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function readiness_is_ok_when_database_ready(): void
    {
        $this->get('/readyz')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function readiness_fails_when_database_unreachable_but_liveness_stays_ok(): void
    {
        // 另建一個指向不可達位址的連線並設為預設，原測試連線（含 RefreshDatabase 交易）保持不動
        config(['database.connections.mysql_down' => array_merge(config('database.connections.mysql'), [
            'host' => '203.0.113.1',
            'options' => [\PDO::ATTR_TIMEOUT => 1],
        ])]);
        $original = config('database.default');
        config(['database.default' => 'mysql_down']);

        $this->get('/readyz')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->get('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);

        config(['database.default' => $original]);
        DB::purge('mysql_down');
    }

    #[Test]
    public function internal_diagnostics_requires_token(): void
    {
        config(['mysite.diagnostics_token' => 'expected-token']);
        $this->get('/internal/diagnostics/php')->assertNotFound();
        $this->get('/internal/diagnostics/php', ['X-Mysite-Diagnostics-Token' => 'wrong'])->assertNotFound();
        $response = $this->get('/internal/diagnostics/php', ['X-Mysite-Diagnostics-Token' => 'expected-token'])->assertOk();
        $this->assertSame('laravel', $response->json()['service']);
        $this->assertArrayHasKey('session.upload_progress.enabled', $response->json());
    }

    #[Test]
    public function internal_routes_reject_forwarded_requests(): void
    {
        config(['mysite.diagnostics_token' => 'expected-token']);
        $this->get('/internal/diagnostics/php', ['X-Mysite-Diagnostics-Token' => 'expected-token', 'X-Forwarded-For' => '198.51.100.7'])
            ->assertNotFound();
    }
}
