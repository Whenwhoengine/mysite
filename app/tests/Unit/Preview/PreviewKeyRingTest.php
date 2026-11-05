<?php

declare(strict_types=1);

namespace Tests\Unit\Preview;

use App\Domain\Preview\PreviewInvalidException;
use App\Domain\Preview\PreviewTicketVerifier;
use App\Support\Errors\ErrorCode;
use App\Support\Secrets\KeyRing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * API-11／GAP-KEYRING-01：預覽票券 header 的 kid 選擇金鑰；未知 kid 拒絕；沒有 kid 的舊格式只用 active key。
 */
final class PreviewKeyRingTest extends TestCase
{
    private const K1 = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    private const K2 = 'd2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2';

    private const NOW = 1_800_000_000;

    private function verifier(string $ring): PreviewTicketVerifier
    {
        return PreviewTicketVerifier::fromKeyRing(KeyRing::parse('TEST', $ring), 'mysite-cms', 'mysite-app', 600, 900);
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'iss' => 'mysite-cms', 'aud' => 'mysite-app', 'typ' => 'preview', 'ctype' => 'post', 'cid' => 123,
            'sid' => str_repeat('ab', 16), 'rev' => 456, 'iat' => self::NOW - 10, 'exp' => self::NOW + 590,
            'jti' => str_repeat('cd', 16), 'sub' => 7,
        ];
    }

    private function ticket(string $key, ?string $kid, array $extraHeader = []): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'mysite-preview'];
        if ($kid !== null) {
            $header['kid'] = $kid;
        }

        return PreviewTicketVerifier::issue($this->claims(), $key, $header + $extraHeader);
    }

    private function reason(PreviewTicketVerifier $v, string $ticket): string
    {
        try {
            $v->verify($ticket, self::NOW);
        } catch (PreviewInvalidException $e) {
            $this->assertSame(ErrorCode::PreviewInvalid, $e->errorCode, '沿用既有錯誤碼 PREVIEW_INVALID');

            return $e->reason;
        }
        $this->fail('預期 PREVIEW_INVALID');
    }

    #[Test]
    #[Group('API-11')]
    #[Group('OPS-01')]
    public function kid_selects_active_or_accept_keys(): void
    {
        $add = $this->verifier('k1 '.self::K1." active\nk2 ".self::K2." accept\n");

        $this->assertSame(123, $add->verify($this->ticket(self::K1, 'k1'), self::NOW)->cid, 'active kid 通過');
        $this->assertSame(123, $add->verify($this->ticket(self::K2, 'k2'), self::NOW)->cid, 'accept kid 通過');
        $this->assertSame('unknown_kid', $this->reason($add, $this->ticket(self::K1, 'k9')), '未知 kid 拒絕');
        $this->assertSame('signature', $this->reason($add, $this->ticket(self::K2, 'k1')), 'kid 與金鑰不符');

        $promote = $this->verifier('k1 '.self::K1." accept\nk2 ".self::K2." active\n");
        $this->assertSame(123, $promote->verify($this->ticket(self::K1, 'k1'), self::NOW)->cid, 'promote 後舊 kid 在 retire 前仍可用（已核發票券）');
        $this->assertSame(123, $promote->verify($this->ticket(self::K2, 'k2'), self::NOW)->cid);
    }

    #[Test]
    #[Group('API-11')]
    #[Group('OPS-01')]
    public function retired_kid_is_rejected(): void
    {
        $retired = $this->verifier('k2 '.self::K2." active\n");

        $this->assertSame('unknown_kid', $this->reason($retired, $this->ticket(self::K1, 'k1')), 'retire 後舊 kid 拒絕');
        $this->assertSame('signature', $this->reason($retired, $this->ticket(self::K1, null)), 'retire 後以舊金鑰簽的無 kid 票券也拒絕');
        $this->assertSame(123, $retired->verify($this->ticket(self::K2, 'k2'), self::NOW)->cid);
    }

    #[Test]
    #[Group('API-11')]
    public function tickets_without_kid_use_only_the_active_key(): void
    {
        $promote = $this->verifier('k1 '.self::K1." accept\nk2 ".self::K2." active\n");

        $this->assertSame(123, $promote->verify($this->ticket(self::K2, null), self::NOW)->cid, '舊格式（無 kid）以 active key 驗證');
        $this->assertSame('signature', $this->reason($promote, $this->ticket(self::K1, null)), '無 kid 時不嘗試 accept key');

        $legacy = new PreviewTicketVerifier('legacy-preview-key', 'mysite-cms', 'mysite-app', 600, 900);
        $this->assertSame(['k1' => 'active'], $legacy->keyRing()->states(), '沒有金鑰圈：單一金鑰視為 k1 active');
        $this->assertSame(123, $legacy->verify($this->ticket('legacy-preview-key', null), self::NOW)->cid);
        $this->assertSame(123, $legacy->verify($this->ticket('legacy-preview-key', 'k1'), self::NOW)->cid, 'WP 新版帶 kid=k1 也相容');
        $this->assertSame('unknown_kid', $this->reason($legacy, $this->ticket('legacy-preview-key', 'k2')));
    }

    #[Test]
    #[Group('API-11')]
    public function malformed_kid_or_extra_header_fields_are_rejected(): void
    {
        $v = $this->verifier('k1 '.self::K1." active\n");

        foreach (['x', 'K1', 'k1234567', ''] as $kid) {
            $this->assertSame('header', $this->reason($v, $this->ticket(self::K1, $kid)), "kid={$kid}");
        }
        $this->assertSame('header', $this->reason($v, PreviewTicketVerifier::issue($this->claims(), self::K1, ['alg' => 'HS256', 'typ' => 'mysite-preview', 'kid' => 1])), 'kid 必須是字串');
        $this->assertSame('header', $this->reason($v, $this->ticket(self::K1, 'k1', ['x5u' => 'https://evil.example/key'])), '多餘 header 欄位');
        $this->assertSame('header', $this->reason($v, PreviewTicketVerifier::issue($this->claims(), self::K1, ['alg' => 'none', 'typ' => 'mysite-preview', 'kid' => 'k1'])));
    }
}
