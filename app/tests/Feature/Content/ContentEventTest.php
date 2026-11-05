<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\Events\ContentEventApplier;
use App\Domain\Content\Events\ContentEventReceipt;
use App\Jobs\Content\ProcessContentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * POST /internal/content-events/v1（契約 §4）：API-06 驗簽、API-07 去重／衝突、API-08 重新簽送達時間。
 */
final class ContentEventTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    private const EVENT_ID = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        $this->freezeSecond();
    }

    private function generation(): int
    {
        return $this->app->make(ContentCache::class)->generation();
    }

    #[Test]
    #[Group('API-06')]
    #[Group('API-07')]
    public function a_valid_event_is_persisted_then_processed_on_the_content_queue(): void
    {
        $body = self::eventBody();

        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $receipt = ContentEventReceipt::query()->where('event_id', self::EVENT_ID)->sole();
        $this->assertSame(hash('sha256', $body), $receipt->body_sha256);
        $this->assertSame('k1', $receipt->key_id);
        $this->assertSame(101, $receipt->content_id);
        $this->assertSame(6, $receipt->content_version);
        $this->assertSame(1, $receipt->received_count);
        $this->assertSame(ContentEventReceipt::STATE_PROCESSED, $receipt->state);
        $this->assertSame(1, $this->generation(), '內容世代號 +1（列表類快取失效）');
    }

    #[Test]
    #[Group('API-07')]
    public function the_job_is_dispatched_to_the_content_queue_after_commit(): void
    {
        Queue::fake();
        $body = self::eventBody();

        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202);

        Queue::assertPushedOn('content', ProcessContentEvent::class, fn (ProcessContentEvent $job): bool => $job->afterCommit === true
            && $job->receiptId === ContentEventReceipt::query()->where('event_id', self::EVENT_ID)->value('id'));
        $this->assertSame(ContentEventReceipt::STATE_PENDING, ContentEventReceipt::query()->value('state'));
    }

    #[Test]
    #[Group('API-07')]
    #[Group('API-08')]
    public function redelivery_with_a_new_timestamp_is_acknowledged_but_applied_once(): void
    {
        $body = self::eventBody();
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202);

        // outbox 延遲重試：同 body、同 event_id、新的送達時間與簽章
        $this->travel(3600)->seconds();
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))
            ->assertStatus(202)
            ->assertExactJson(['status' => 'duplicate']);

        $receipt = ContentEventReceipt::query()->sole();
        $this->assertSame(2, $receipt->received_count);
        $this->assertSame(1, $this->generation(), '只套用一次');
    }

    #[Test]
    #[Group('API-07')]
    public function same_id_with_different_content_is_rejected_and_logged(): void
    {
        $body = self::eventBody();
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202);
        Log::spy();

        $forged = self::eventBody(['content_id' => 999, 'event_type' => 'content.deleted', 'status' => 'deleted']);
        $this->postEvent($forged, self::signedEventHeaders($forged, self::EVENT_ID))
            ->assertStatus(409)
            ->assertExactJson(['error' => 'EVENT_CONFLICT']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []): bool => $m === 'content_event_conflict' && $c['event_id'] === self::EVENT_ID);
        $this->assertSame(1, ContentEventReceipt::query()->count());
        $this->assertSame(101, ContentEventReceipt::query()->value('content_id'));
        $this->assertSame(1, $this->generation());
    }

    #[Test]
    #[Group('API-06')]
    public function invalid_signatures_are_401_and_never_persisted(): void
    {
        $body = self::eventBody();
        $now = now()->getTimestamp();
        $good = self::signedEventHeaders($body, self::EVENT_ID, $now);

        $cases = [
            '竄改 body' => [self::eventBody(['content_version' => 7]), $good],
            '竄改 timestamp' => [$body, array_merge($good, ['X-Mysite-Timestamp' => (string) ($now + 1)])],
            '未知 key id' => [$body, array_merge($good, ['X-Mysite-Key-Id' => 'k9'])],
            '錯誤金鑰' => [$body, self::signedEventHeaders($body, self::EVENT_ID, $now, 'k1', 'other-key')],
            '時差超過 300 秒' => [$body, self::signedEventHeaders($body, self::EVENT_ID, $now - 301)],
            '未來時間超過 300 秒' => [$body, self::signedEventHeaders($body, self::EVENT_ID, $now + 301)],
            '缺簽章' => [$body, array_diff_key($good, ['X-Mysite-Signature' => true])],
            '缺 key id' => [$body, array_diff_key($good, ['X-Mysite-Key-Id' => true])],
            '缺 event id' => [$body, array_diff_key($good, ['X-Mysite-Event-Id' => true])],
            'event id header 被換' => [$body, array_merge($good, ['X-Mysite-Event-Id' => '00000000-0000-4000-8000-000000000000'])],
        ];
        foreach ($cases as $label => [$sentBody, $headers]) {
            $this->postEvent($sentBody, $headers)->assertStatus(401)->assertExactJson(['error' => 'EVENT_SIGNATURE_INVALID']);
            $this->assertSame(0, ContentEventReceipt::query()->count(), $label);
        }
        $this->assertSame(0, $this->generation(), '驗簽失敗不寫入快取');
    }

    #[Test]
    #[Group('API-06')]
    public function malformed_bodies_are_422(): void
    {
        $cases = [
            'not json' => ['{not json', self::EVENT_ID],
            '缺欄位' => [(string) json_encode(['event_id' => self::EVENT_ID]), self::EVENT_ID],
            '型別錯' => [self::eventBody(['content_type' => 'product']), self::EVENT_ID],
            'header 與 body event id 不同' => [self::eventBody(['event_id' => '00000000-0000-4000-8000-000000000000']), self::EVENT_ID],
            '過大' => [self::eventBody(['pad' => str_repeat('x', 17000)]), self::EVENT_ID],
        ];
        foreach ($cases as $label => [$body, $eventId]) {
            $this->postEvent($body, self::signedEventHeaders($body, $eventId))->assertStatus(422)->assertExactJson(['error' => 'EVENT_INVALID']);
        }
        $body = self::eventBody();
        $this->postEvent($body, array_merge(self::signedEventHeaders($body, self::EVENT_ID), ['Content-Type' => 'text/plain']))->assertStatus(422);
        $this->assertSame(0, ContentEventReceipt::query()->count());
    }

    #[Test]
    #[Group('API-06')]
    public function the_endpoint_is_internal_only_and_fails_closed_without_a_key(): void
    {
        $body = self::eventBody();
        $headers = self::signedEventHeaders($body, self::EVENT_ID);

        $this->postEvent($body, array_merge($headers, ['X-Forwarded-For' => '198.51.100.7']))->assertNotFound();
        $this->getJson('/internal/content-events/v1')->assertStatus(405);

        config(['mysite.content.event_hmac_key' => '']);
        $this->postEvent($body, $headers)->assertStatus(503)->assertExactJson(['error' => 'EVENT_RECEIVER_UNAVAILABLE']);
        $this->assertSame(0, ContentEventReceipt::query()->count());
    }

    #[Test]
    #[Group('API-07')]
    public function failed_processing_is_retried_on_redelivery_but_processed_events_are_not(): void
    {
        Queue::fake();
        $body = self::eventBody();
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202);
        $id = (int) ContentEventReceipt::query()->value('id');

        $this->app->make(ContentEventApplier::class)->markFailed($id, 'RuntimeException');
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202)->assertExactJson(['status' => 'duplicate']);
        Queue::assertPushed(ProcessContentEvent::class, 2);

        // 處理完成後，再收到重送與重複執行同一工作都不再套用
        (new ProcessContentEvent($id))->handle($this->app->make(ContentEventApplier::class));
        $generation = $this->generation();
        (new ProcessContentEvent($id))->handle($this->app->make(ContentEventApplier::class));
        $this->postEvent($body, self::signedEventHeaders($body, self::EVENT_ID))->assertStatus(202);
        Queue::assertPushed(ProcessContentEvent::class, 2);
        $this->assertSame($generation, $this->generation());
        $this->assertSame(3, ContentEventReceipt::query()->value('received_count'));

        (new ProcessContentEvent($id))->failed(new \RuntimeException('x'));
        $this->assertSame(ContentEventReceipt::STATE_FAILED, ContentEventReceipt::query()->value('state'));
    }
}
