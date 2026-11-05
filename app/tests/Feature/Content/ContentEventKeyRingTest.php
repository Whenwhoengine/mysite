<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Domain\Content\Events\ContentEventReceipt;
use App\Support\Secrets\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * POST /internal/content-events/v1 搭配金鑰圈（GAP-KEYRING-01）：add → promote → retire 每期接收端行為，
 * 以及 `mysite:keyring` 只輸出 key ID 與狀態。
 */
final class ContentEventKeyRingTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    private const K1 = '9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f';

    private const K2 = '8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        $this->freezeSecond();
        Queue::fake(); // 本測試只驗接收端驗簽與收據，事件處理另由 ContentEventTest 驗證
    }

    private function useRing(string $ring): void
    {
        config(['mysite.content.event_keyring' => KeyRing::parse('TEST', $ring)->toCompact()]);
    }

    /** 以指定 key ID／金鑰送出一筆新事件（每次不同 event_id），回傳 HTTP 狀態。 */
    private function send(string $keyId, string $key): int
    {
        $eventId = sprintf('3f1c2b4a-5d6e-4f70-8a9b-%012d', ++$this->seq);
        $body = self::eventBody(['event_id' => $eventId, 'content_id' => 100 + $this->seq]);

        return $this->postEvent($body, self::signedEventHeaders($body, $eventId, null, $keyId, $key))->getStatusCode();
    }

    #[Test]
    #[Group('API-06')]
    #[Group('OPS-01')]
    public function each_rotation_phase_accepts_exactly_the_keys_in_the_keyring(): void
    {
        // add：k1 active（WP 仍以 k1 簽）、k2 accept（接收端先認得新 key）
        $this->useRing('k1 '.self::K1." active\nk2 ".self::K2." accept\n");
        $this->assertSame(202, $this->send('k1', self::K1));
        $this->assertSame(202, $this->send('k2', self::K2));
        $this->assertSame(401, $this->send('k3', self::K1), '未知 key ID');
        $this->assertSame(401, $this->send('k1', self::EVENT_KEY), '有金鑰圈時不再使用單一金鑰');

        // promote：k2 active、k1 accept（在途的舊簽章仍可送達）
        $this->useRing('k1 '.self::K1." accept\nk2 ".self::K2." active\n");
        $this->assertSame(202, $this->send('k2', self::K2));
        $this->assertSame(202, $this->send('k1', self::K1));

        // retire：只剩 k2；舊 key 一律 401，且不落地
        $this->useRing('k2 '.self::K2." active\n");
        $before = ContentEventReceipt::query()->count();
        $this->postEvent(self::eventBody(['event_id' => '3f1c2b4a-5d6e-4f70-8a9b-999999999999']), self::signedEventHeaders(
            self::eventBody(['event_id' => '3f1c2b4a-5d6e-4f70-8a9b-999999999999']), '3f1c2b4a-5d6e-4f70-8a9b-999999999999', null, 'k1', self::K1,
        ))->assertStatus(401)->assertExactJson(['error' => 'EVENT_SIGNATURE_INVALID']);
        $this->assertSame($before, ContentEventReceipt::query()->count(), '被拒的事件不寫入收據');
        $this->assertSame(202, $this->send('k2', self::K2));

        $this->assertSame(['k1', 'k2'], ContentEventReceipt::query()->distinct()->orderBy('key_id')->pluck('key_id')->all(), '收據記錄實際使用的 key ID');
    }

    #[Test]
    #[Group('API-06')]
    public function without_a_keyring_the_single_key_still_works(): void
    {
        config(['mysite.content.event_keyring' => null]);

        $this->assertSame(202, $this->send('k1', self::EVENT_KEY), '沒有金鑰圈：單一金鑰視為 k1 active');
        $this->assertSame(401, $this->send('k2', self::EVENT_KEY));
    }

    #[Test]
    #[Group('OPS-01')]
    public function keyring_status_command_lists_ids_and_states_without_key_material(): void
    {
        $this->useRing('k2 '.self::K2." active\nk1 ".self::K1." accept\n");
        config(['mysite.preview.signing_keyring' => KeyRing::parse('TEST', 'k3 '.self::K1." active\n")->toCompact()]);

        $this->artisan('mysite:keyring', ['kind' => 'event-hmac'])
            ->expectsOutput('k1 accept')
            ->expectsOutput('k2 active')
            ->doesntExpectOutputToContain(self::K1)
            ->doesntExpectOutputToContain(self::K2)
            ->assertExitCode(0);
        $this->artisan('mysite:keyring', ['kind' => 'preview'])
            ->expectsOutput('k3 active')
            ->doesntExpectOutputToContain(self::K1)
            ->assertExitCode(0);
        $this->artisan('mysite:keyring', ['kind' => 'other'])->assertExitCode(10);

        config(['mysite.preview.signing_keyring' => null]);
        $this->artisan('mysite:keyring', ['kind' => 'preview'])->expectsOutput('k1 active')->assertExitCode(0);

        $old = 'base64:'.base64_encode(random_bytes(32));
        config(['app.previous_keys' => [$old]]);
        $this->artisan('mysite:keyring', ['kind' => 'app-key'])->expectsOutput('previous_keys 1')->doesntExpectOutputToContain($old)->assertExitCode(0);
        config(['app.previous_keys' => []]);
        $this->artisan('mysite:keyring', ['kind' => 'app-key'])->expectsOutput('previous_keys 0')->assertExitCode(0);
    }
}
