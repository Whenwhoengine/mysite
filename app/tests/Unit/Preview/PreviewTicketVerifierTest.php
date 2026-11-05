<?php

declare(strict_types=1);

namespace Tests\Unit\Preview;

use App\Domain\Content\ContentType;
use App\Domain\Preview\PreviewInvalidException;
use App\Domain\Preview\PreviewTicketVerifier;
use App\Support\Errors\ErrorCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** API-11：預覽票券驗簽與 iss／aud／typ／exp／TTL 各失敗情境。 */
final class PreviewTicketVerifierTest extends TestCase
{
    private const KEY = 'unit-preview-key';

    private const NOW = 1_800_000_000;

    private function verifier(int $ttl = 600): PreviewTicketVerifier
    {
        return new PreviewTicketVerifier(self::KEY, 'mysite-cms', 'mysite-app', $ttl, 900);
    }

    /** @return array<string, mixed> */
    private function claims(array $override = []): array
    {
        return array_merge([
            'iss' => 'mysite-cms', 'aud' => 'mysite-app', 'typ' => 'preview', 'ctype' => 'video', 'cid' => 123,
            'sid' => str_repeat('ab', 16), 'rev' => 456, 'iat' => self::NOW - 10, 'exp' => self::NOW + 590,
            'jti' => str_repeat('cd', 16), 'sub' => 7,
        ], $override);
    }

    private function reason(string $ticket, ?PreviewTicketVerifier $verifier = null): string
    {
        try {
            ($verifier ?? $this->verifier())->verify($ticket, self::NOW);
        } catch (PreviewInvalidException $e) {
            $this->assertSame(ErrorCode::PreviewInvalid, $e->errorCode);
            $this->assertSame(403, $e->errorCode->status());
            if ($ticket !== '') {
                $this->assertStringNotContainsString($ticket, $e->getMessage(), '例外訊息不得含票券');
            }

            return $e->reason;
        }
        $this->fail('預期 PREVIEW_INVALID');
    }

    #[Test]
    #[Group('API-11')]
    public function a_valid_ticket_yields_bound_claims(): void
    {
        $claims = $this->verifier()->verify(PreviewTicketVerifier::issue($this->claims(), self::KEY), self::NOW);

        $this->assertSame(ContentType::Video, $claims->ctype);
        $this->assertSame(123, $claims->cid);
        $this->assertSame(str_repeat('ab', 16), $claims->sid);
        $this->assertSame(456, $claims->rev);
        $this->assertSame(str_repeat('cd', 16), $claims->jti);
        $this->assertSame(7, $claims->sub);
    }

    #[Test]
    #[Group('API-11')]
    public function signature_and_format_failures(): void
    {
        $ticket = PreviewTicketVerifier::issue($this->claims(), self::KEY);
        [$h, $c, $s] = explode('.', $ticket);

        $this->assertSame('signature', $this->reason(PreviewTicketVerifier::issue($this->claims(), 'wrong-key')));
        $tampered = PreviewTicketVerifier::b64url((string) json_encode($this->claims(['cid' => 124])));
        $this->assertSame('signature', $this->reason($h.'.'.$tampered.'.'.$s), '竄改 claims（跨內容）');
        $this->assertSame('signature', $this->reason($h.'.'.$c.'.'.strrev($s)));
        $this->assertSame('format', $this->reason($h.'.'.$c));
        $this->assertSame('format', $this->reason($ticket.'='));
        $this->assertSame('format', $this->reason($ticket."\n"));
        $this->assertSame('format', $this->reason(str_repeat('a', 5000).'.b.c'));
        $this->assertSame('format', $this->reason(''));
        $this->assertSame('signing_key_missing', $this->reason($ticket, new PreviewTicketVerifier('', 'mysite-cms', 'mysite-app', 600, 900)));
    }

    #[Test]
    #[Group('API-11')]
    public function header_must_be_exactly_hs256_mysite_preview(): void
    {
        foreach ([['alg' => 'none', 'typ' => 'mysite-preview'], ['alg' => 'HS256', 'typ' => 'JWT'], ['alg' => 'HS256', 'typ' => 'mysite-preview', 'kid' => 'x'], ['alg' => 'HS512', 'typ' => 'mysite-preview']] as $header) {
            $this->assertSame('header', $this->reason(PreviewTicketVerifier::issue($this->claims(), self::KEY, $header)), json_encode($header));
        }
    }

    #[Test]
    #[Group('API-11')]
    public function claim_failures(): void
    {
        $cases = [
            'iss' => ['iss' => 'someone-else'],
            'aud' => ['aud' => 'mysite-admin'],
            'typ' => ['typ' => 'login'],
            'expired' => ['iat' => self::NOW - 700, 'exp' => self::NOW - 100],
            'ttl' => ['iat' => self::NOW, 'exp' => self::NOW + 601],
            'iat_future' => ['iat' => self::NOW + 120, 'exp' => self::NOW + 600],
            'claims' => ['sid' => 'not-hex'],
            'claims_cid' => ['cid' => '123'],
        ];
        foreach ($cases as $reason => $override) {
            $this->assertSame($reason, $this->reason(PreviewTicketVerifier::issue($this->claims($override), self::KEY)), $reason);
        }
        $this->assertSame('claims', $this->reason(PreviewTicketVerifier::issue($this->claims(['ctype' => 'product']), self::KEY)));
        $this->assertSame('claims', $this->reason(PreviewTicketVerifier::issue($this->claims(['jti' => str_repeat('z', 32)]), self::KEY)));
        $this->assertSame('ttl', $this->reason(PreviewTicketVerifier::issue($this->claims(['exp' => self::NOW - 10]), self::KEY)), 'exp ≤ iat');
    }

    #[Test]
    #[Group('API-11')]
    public function ttl_hard_limit_is_900_even_if_configured_higher(): void
    {
        $verifier = new PreviewTicketVerifier(self::KEY, 'mysite-cms', 'mysite-app', 3600, 3600);

        $this->assertSame('ttl', $this->reason(PreviewTicketVerifier::issue($this->claims(['iat' => self::NOW, 'exp' => self::NOW + 901]), self::KEY), $verifier));
        $this->assertSame(123, $verifier->verify(PreviewTicketVerifier::issue($this->claims(['iat' => self::NOW, 'exp' => self::NOW + 900]), self::KEY), self::NOW)->cid);
    }
}
