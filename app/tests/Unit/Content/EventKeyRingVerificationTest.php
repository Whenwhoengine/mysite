<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\Events\EventSignatureVerifier;
use App\Support\Secrets\KeyRing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * API-06／GAP-KEYRING-01：事件 HMAC 依 X-Mysite-Key-Id 在金鑰圈中選金鑰；輪替三期（add → promote → retire）。
 */
final class EventKeyRingVerificationTest extends TestCase
{
    private const K1 = '1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';

    private const K2 = '2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b';

    private const EVENT_ID = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    private const NOW = 1_800_000_000;

    private const BODY = '{"event_id":"3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b","event_type":"content.updated"}';

    private function verifier(string $ring): EventSignatureVerifier
    {
        return EventSignatureVerifier::fromKeyRing(KeyRing::parse('TEST', $ring), 300);
    }

    private function check(EventSignatureVerifier $v, ?string $headerKeyId, string $signingKey): ?string
    {
        $sig = EventSignatureVerifier::sign($signingKey, (string) self::NOW, self::EVENT_ID, self::BODY);

        return $v->failure($headerKeyId, (string) self::NOW, self::EVENT_ID, $sig, self::BODY, self::NOW);
    }

    #[Test]
    #[Group('API-06')]
    #[Group('OPS-01')]
    public function add_phase_active_key_signs_and_new_accept_key_is_already_verifiable(): void
    {
        $v = $this->verifier('k1 '.self::K1." active\nk2 ".self::K2." accept\n");

        $this->assertTrue($v->isConfigured());
        $this->assertNull($this->check($v, 'k1', self::K1), 'active key 簽章通過');
        $this->assertNull($this->check($v, 'k2', self::K2), 'accept key 簽章通過（接收端先認得新 key）');
        $this->assertSame('signature_mismatch', $this->check($v, 'k1', self::K2), 'key ID 與金鑰不符');
        $this->assertSame('signature_mismatch', $this->check($v, 'k2', self::K1));
        $this->assertSame('unknown_key_id', $this->check($v, 'k3', self::K1), '不在金鑰圈的 key ID');
        $this->assertSame('unknown_key_id', $this->check($v, 'K1', self::K1));
        $this->assertSame('missing_header', $this->check($v, null, self::K1), '契約 §4：key ID 必填');
        $this->assertSame('missing_header', $this->check($v, '', self::K1));
    }

    #[Test]
    #[Group('API-06')]
    #[Group('OPS-01')]
    public function promote_phase_new_key_signs_and_old_key_is_still_accepted(): void
    {
        $v = $this->verifier('k1 '.self::K1." accept\nk2 ".self::K2." active\n");

        $this->assertNull($this->check($v, 'k2', self::K2), '新 active key 通過');
        $this->assertNull($this->check($v, 'k1', self::K1), '舊 key（accept）在 retire 前仍通過（在途事件）');
        $this->assertSame('k2', $v->keyRing()->activeId());
    }

    #[Test]
    #[Group('API-06')]
    #[Group('OPS-01')]
    public function after_retire_the_old_key_is_rejected(): void
    {
        $v = $this->verifier('k2 '.self::K2." active\n");

        $this->assertNull($this->check($v, 'k2', self::K2));
        $this->assertSame('unknown_key_id', $this->check($v, 'k1', self::K1), 'retire 後舊 key ID 不在金鑰圈 → 拒絕');
        $this->assertSame('signature_mismatch', $this->check($v, 'k2', self::K1), '舊金鑰冒用新 key ID 也拒絕');
    }

    #[Test]
    #[Group('API-06')]
    public function single_key_mode_keeps_the_legacy_behaviour(): void
    {
        $legacy = new EventSignatureVerifier('k1', 'legacy-event-key', 300);

        $this->assertSame(['k1' => 'active'], $legacy->keyRing()->states());
        $this->assertNull($this->check($legacy, 'k1', 'legacy-event-key'), '沒有金鑰圈：沿用單一金鑰（視為 k1 active）');
        $this->assertSame('unknown_key_id', $this->check($legacy, 'k2', 'legacy-event-key'));

        $ringWins = new EventSignatureVerifier('k1', 'legacy-event-key', 300, KeyRing::parse('TEST', 'k2 '.self::K2.' active'));
        $this->assertSame('unknown_key_id', $this->check($ringWins, 'k1', 'legacy-event-key'), '有金鑰圈時不再接受單一金鑰');
        $this->assertNull($this->check($ringWins, 'k2', self::K2));
    }
}
