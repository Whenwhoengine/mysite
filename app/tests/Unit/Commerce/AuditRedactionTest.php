<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Audit\IpMinimizer;
use App\Domain\Audit\MetadataRedactor;
use App\Models\OrderStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** 稽核去秘密化與 IP 最小化（DAT-02、COM-10）。 */
final class AuditRedactionTest extends TestCase
{
    #[Test]
    #[Group('DAT-02')]
    public function masks_secret_like_keys_recursively(): void
    {
        $out = MetadataRedactor::redact([
            'password' => 'hunter2hunter2',
            'current_password' => 'x',
            'two_factor_secret' => 'ABC',
            'recovery_code' => 'abcd-efgh',
            'idempotency_key' => 'k',
            'order_token' => 't',
            'card_number' => '4111111111111111',
            'Authorization' => 'Bearer x',
            'email' => 'a@example.com',
            'phone' => '0912',
            'nested' => ['api_key' => 'z', 'ok' => 'visible'],
            'sku' => 'SKU-1',
            'reason' => 'manual',
            'status' => 'active',
            'email_hash' => 'abc123',
            'delta' => -3,
            'flag' => true,
        ]);
        foreach (['password', 'current_password', 'two_factor_secret', 'recovery_code', 'idempotency_key', 'order_token', 'card_number', 'Authorization', 'email', 'phone'] as $k) {
            $this->assertSame(MetadataRedactor::MASK, $out[$k], $k);
        }
        $this->assertSame(MetadataRedactor::MASK, $out['nested']['api_key']);
        $this->assertSame('visible', $out['nested']['ok']);
        $this->assertSame('SKU-1', $out['sku']);
        $this->assertSame('manual', $out['reason']);
        $this->assertSame('abc123', $out['email_hash']);
        $this->assertSame(-3, $out['delta']);
        $this->assertTrue($out['flag']);
        $this->assertStringNotContainsString('4111', json_encode($out));
        $this->assertStringNotContainsString('hunter2', json_encode($out));
    }

    #[Test]
    #[Group('DAT-02')]
    public function truncates_long_values_and_limits_depth_and_items(): void
    {
        $out = MetadataRedactor::redact([
            'long' => str_repeat('長', 500),
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => 'x']]]]],
            'many' => array_fill(0, 80, 1),
            'float' => 1.5,
            'enum' => OrderStatus::Placed,
            'obj' => new \stdClass,
        ]);
        $this->assertSame(201, mb_strlen($out['long']));
        $this->assertSame('[巢狀過深]', $out['deep']['a']['b']['c']['d']);
        $this->assertTrue($out['many']['_truncated']);
        $this->assertSame('1.5', $out['float']);
        $this->assertSame('placed', $out['enum']);
        $this->assertSame('[stdClass]', $out['obj']);
    }

    #[Test]
    #[Group('DAT-02')]
    public function minimizes_ip_addresses(): void
    {
        $this->assertSame('203.0.113.0/24', IpMinimizer::minimize('203.0.113.77'));
        $this->assertSame('2001:db8:abcd::/48', IpMinimizer::minimize('2001:db8:abcd:12:34::1'));
        $this->assertNull(IpMinimizer::minimize(null));
        $this->assertNull(IpMinimizer::minimize(''));
        $this->assertNull(IpMinimizer::minimize('not-an-ip'));
    }
}
