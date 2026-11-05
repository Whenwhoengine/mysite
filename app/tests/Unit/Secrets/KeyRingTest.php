<?php

declare(strict_types=1);

namespace Tests\Unit\Secrets;

use App\Support\Secrets\KeyRing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** GAP-KEYRING-01：金鑰圈檔解析（格式、恰好一把 active、64 hex、key ID）與相容模式。 */
final class KeyRingTest extends TestCase
{
    private const K1 = '1111111111111111111111111111111111111111111111111111111111111111';

    private const K2 = 'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdef0123';

    private function reject(string $content): string
    {
        try {
            KeyRing::parse('TEST_KEYRING_FILE', $content);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::K1, $e->getMessage(), '錯誤訊息不得含金鑰');
            $this->assertStringNotContainsString(self::K2, $e->getMessage(), '錯誤訊息不得含金鑰');

            return $e->getMessage();
        }
        $this->fail('預期金鑰圈被拒絕');
    }

    #[Test]
    #[Group('OPS-01')]
    #[Group('GOV-01')]
    public function a_valid_keyring_is_parsed_with_exactly_one_active_key(): void
    {
        $ring = KeyRing::parse('X', "k2 ".self::K2." accept\r\n\n  k1\t".self::K1."   active  \n");

        $this->assertSame('k1', $ring->activeId());
        $this->assertSame(self::K1, $ring->activeKey());
        $this->assertSame(self::K2, $ring->find('k2'), 'accept key 可供驗證');
        $this->assertSame(self::K1, $ring->find('k1'));
        $this->assertNull($ring->find('k3'));
        $this->assertNull($ring->find('K1'), 'key ID 大小寫敏感');
        $this->assertSame(['k1' => 'active', 'k2' => 'accept'], $ring->states(), '依 key ID 數字排序且不含金鑰');
        $this->assertSame(2, $ring->count());
    }

    #[Test]
    #[Group('OPS-01')]
    #[Group('GOV-01')]
    public function malformed_keyrings_are_rejected(): void
    {
        $cases = [
            '空內容' => ["\n  \n", '內容為空'],
            '欄位不足' => ['k1 '.self::K1, '格式不合法'],
            '欄位過多' => ['k1 '.self::K1.' active extra', '格式不合法'],
            'key ID 大寫' => ['K1 '.self::K1.' active', 'key ID 不合法'],
            'key ID 太長' => ['k1234567 '.self::K1.' active', 'key ID 不合法'],
            'key ID 非 k 開頭' => ['x1 '.self::K1.' active', 'key ID 不合法'],
            '金鑰 63 字元' => ['k1 '.substr(self::K1, 1).' active', '64 字元小寫 hex'],
            '金鑰大寫 hex' => ['k1 '.strtoupper(self::K2).' active', '64 字元小寫 hex'],
            '金鑰非 hex' => ['k1 '.str_repeat('g', 64).' active', '64 字元小寫 hex'],
            '狀態不合法' => ['k1 '.self::K1.' primary', 'active 或 accept'],
            '沒有 active' => ['k1 '.self::K1." accept\nk2 ".self::K2.' accept', '沒有 active'],
            '兩把 active' => ['k1 '.self::K1." active\nk2 ".self::K2.' active', '發現多把'],
            'key ID 重複' => ['k1 '.self::K1." active\nk1 ".self::K2.' accept', '重複'],
            '註解行' => ['# comment line here', '格式不合法'],
        ];
        foreach ($cases as $label => [$content, $expected]) {
            $this->assertStringContainsString($expected, $this->reject($content), $label);
        }
    }

    #[Test]
    #[Group('OPS-01')]
    public function compact_form_round_trips_and_is_validated_as_strictly(): void
    {
        $ring = KeyRing::parse('X', 'k1 '.self::K1." accept\nk2 ".self::K2." active\n");
        $compact = $ring->toCompact();

        $this->assertStringNotContainsString("\n", $compact);
        $again = KeyRing::fromCompact('X', $compact);
        $this->assertSame('k2', $again->activeId());
        $this->assertSame(self::K2, $again->activeKey());
        $this->assertSame(self::K1, $again->find('k1'));

        foreach (['', 'k1:'.self::K1, 'k1:'.self::K1.':accept', 'k1:'.self::K1.':active,k1:'.self::K2.':accept', 'k1:short:active'] as $bad) {
            try {
                KeyRing::fromCompact('X', $bad);
                $this->fail('精簡格式也必須嚴格驗證：'.substr($bad, 0, 12));
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString(self::K1, $e->getMessage());
            }
        }
    }

    #[Test]
    #[Group('OPS-01')]
    public function single_key_mode_is_one_active_key_for_backward_compatibility(): void
    {
        $ring = KeyRing::single('k1', 'legacy-single-key');

        $this->assertSame('k1', $ring->activeId());
        $this->assertSame('legacy-single-key', $ring->activeKey());
        $this->assertSame(['k1' => 'active'], $ring->states());
        $this->assertNull($ring->find('k2'));
        $this->assertSame('', KeyRing::single('k1', '')->activeKey(), '未設定金鑰時 active key 為空字串（由呼叫端視為未設定）');
    }
}
