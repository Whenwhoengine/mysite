<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\Events\ContentEventPayload;
use App\Domain\Content\Events\EventSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** API-06：HMAC 驗簽（原始 body、時差窗、key ID、常數時間）與事件 body 結構驗證。 */
final class ContentEventVerificationTest extends TestCase
{
    private const KEY = 'unit-test-key';

    private const EVENT_ID = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    private function verifier(): EventSignatureVerifier
    {
        return new EventSignatureVerifier('k1', self::KEY, 300);
    }

    private function body(array $override = []): string
    {
        return (string) json_encode(array_merge([
            'event_id' => self::EVENT_ID,
            'event_type' => 'content.unpublished',
            'content_type' => 'post',
            'content_id' => 123,
            'content_version' => 6,
            'status' => 'draft',
            'occurred_at' => '2026-11-05T01:23:45Z',
        ], $override));
    }

    #[Test]
    #[Group('API-06')]
    public function valid_signature_passes(): void
    {
        $now = 1_800_000_000;
        $body = $this->body();
        $sig = EventSignatureVerifier::sign(self::KEY, (string) $now, self::EVENT_ID, $body);

        $this->assertSame(hash_hmac('sha256', $now."\n".self::EVENT_ID."\n".$body, self::KEY), $sig);
        $this->assertNull($this->verifier()->failure('k1', (string) $now, self::EVENT_ID, $sig, $body, $now));
        $this->assertNull($this->verifier()->failure('k1', (string) $now, self::EVENT_ID, $sig, $body, $now + 300), '時差 300 秒內有效');
        $this->assertTrue($this->verifier()->isConfigured());
        $this->assertFalse((new EventSignatureVerifier('k1', '', 300))->isConfigured());
    }

    #[Test]
    #[Group('API-06')]
    public function tampering_and_bad_headers_fail(): void
    {
        $now = 1_800_000_000;
        $body = $this->body();
        $sig = EventSignatureVerifier::sign(self::KEY, (string) $now, self::EVENT_ID, $body);
        $v = $this->verifier();

        $this->assertSame('signature_mismatch', $v->failure('k1', (string) $now, self::EVENT_ID, $sig, $this->body(['content_version' => 7]), $now), '竄改 body');
        $this->assertSame('signature_mismatch', $v->failure('k1', (string) $now, self::EVENT_ID, $sig, $body.' ', $now), 'body 多一個位元組');
        $this->assertSame('signature_mismatch', $v->failure('k1', (string) ($now - 1), self::EVENT_ID, $sig, $body, $now), '竄改 timestamp');
        $this->assertSame('signature_mismatch', $v->failure('k1', (string) $now, '00000000-0000-4000-8000-000000000000', $sig, $body, $now), '竄改 event id');
        $this->assertSame('signature_mismatch', $v->failure('k1', (string) $now, self::EVENT_ID, EventSignatureVerifier::sign('other-key', (string) $now, self::EVENT_ID, $body), $body, $now), '錯誤金鑰');
        $this->assertSame('unknown_key_id', $v->failure('k2', (string) $now, self::EVENT_ID, $sig, $body, $now));
        $this->assertSame('timestamp_skew', $v->failure('k1', (string) $now, self::EVENT_ID, $sig, $body, $now + 301));
        $this->assertSame('timestamp_skew', $v->failure('k1', (string) $now, self::EVENT_ID, $sig, $body, $now - 301));
        $this->assertSame('timestamp_format', $v->failure('k1', '1.5e9', self::EVENT_ID, $sig, $body, $now));
        $this->assertSame('timestamp_format', $v->failure('k1', $now."\n", self::EVENT_ID, $sig, $body, $now));
        $this->assertSame('signature_format', $v->failure('k1', (string) $now, self::EVENT_ID, strtoupper($sig), $body, $now), '只接受小寫 hex');
        $this->assertSame('signature_format', $v->failure('k1', (string) $now, self::EVENT_ID, 'sha256='.$sig, $body, $now));
        foreach ([[null, (string) $now, self::EVENT_ID, $sig], ['k1', null, self::EVENT_ID, $sig], ['k1', (string) $now, null, $sig], ['k1', (string) $now, self::EVENT_ID, null], ['', (string) $now, self::EVENT_ID, $sig]] as [$k, $t, $e, $s]) {
            $this->assertSame('missing_header', $v->failure($k, $t, $e, $s, $body, $now));
        }
    }

    #[Test]
    #[Group('API-06')]
    public function payload_structure_is_validated(): void
    {
        $payload = ContentEventPayload::parse($this->body());
        $this->assertNotNull($payload);
        $this->assertSame(123, $payload->contentId);
        $this->assertTrue($payload->isTakedown());
        $this->assertFalse(ContentEventPayload::parse($this->body(['event_type' => 'content.updated', 'status' => 'publish']))?->isTakedown());

        $invalid = [
            'not json' => '{oops',
            'list' => '[1,2]',
            'uuid v1' => $this->body(['event_id' => '3f1c2b4a-5d6e-1f70-8a9b-0c1d2e3f4a5b']),
            'event type' => $this->body(['event_type' => 'content.exploded']),
            'content type' => $this->body(['content_type' => 'product']),
            'content id string' => $this->body(['content_id' => '123']),
            'content id zero' => $this->body(['content_id' => 0]),
            'version negative' => $this->body(['content_version' => -1]),
            'status' => $this->body(['status' => 'hidden']),
            'occurred_at format' => $this->body(['occurred_at' => '2026-11-05 01:23:45']),
            'occurred_at invalid date' => $this->body(['occurred_at' => '2026-13-45T01:23:45Z']),
            'missing field' => (string) json_encode(['event_id' => self::EVENT_ID]),
        ];
        foreach ($invalid as $label => $raw) {
            $this->assertNull(ContentEventPayload::parse($raw), $label);
        }
    }
}
