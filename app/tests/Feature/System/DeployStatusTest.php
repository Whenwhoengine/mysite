<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** OPS-04／v5 §14.8：部署狀態只對唯讀權杖回應，且只含 release 資訊。 */
final class DeployStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Group('OPS-04')]
    #[Test]
    public function it_is_hidden_without_valid_token(): void
    {
        config(['mysite.deploy.status_token' => 'deploy-status-token-value']);
        $this->getJson('/internal/deploy-status')->assertNotFound();
        $this->getJson('/internal/deploy-status', ['Authorization' => 'Bearer wrong'])->assertNotFound();
    }

    #[Group('OPS-04')]
    #[Test]
    public function it_is_hidden_when_token_not_configured(): void
    {
        config(['mysite.deploy.status_token' => null]);
        $this->getJson('/internal/deploy-status', ['Authorization' => 'Bearer anything'])->assertNotFound();
    }

    #[Group('OPS-04')]
    #[Test]
    public function it_reports_release_identity_without_sensitive_details(): void
    {
        config([
            'mysite.deploy.status_token' => 'deploy-status-token-value',
            'mysite.deploy.release_id' => 'r-2026-11-05-001',
            'mysite.deploy.environment' => 'staging',
        ]);
        $response = $this->getJson('/internal/deploy-status', ['Authorization' => 'Bearer deploy-status-token-value'])
            ->assertOk()
            ->assertJsonPath('release_id', 'r-2026-11-05-001')
            ->assertJsonPath('environment', 'staging')
            ->assertJsonPath('status', 'active')
            ->assertHeader('Cache-Control', 'no-store, private');
        $body = $response->getContent();
        $this->assertStringNotContainsString('mysql', (string) $body);
        $this->assertStringNotContainsString('password', strtolower((string) $body));
        $this->assertArrayNotHasKey('Set-Cookie', $response->headers->all());
    }
}
