<?php

declare(strict_types=1);

namespace Tests\Feature\Preview;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\ContentType;
use App\Domain\Preview\PreviewInvalidException;
use App\Domain\Preview\PreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 草稿預覽（契約 §5）：API-11 票券交換與拒絕情境、API-12 快照一致、API-13 不進公開快取／不索引／不記票券。
 */
final class PreviewFlowTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        $this->freezeSecond();
        Sleep::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** @return array<string, mixed> WP 預覽快照（含 preview 標記與快照 ID） */
    private function snapshot(array $override = []): array
    {
        return array_merge(self::wpItem([
            'id' => 123,
            'slug' => '',
            'title' => '草稿標題（尚未公開）',
            'published_at' => null,
            'body_html' => '<p>草稿正文</p><p><img src="https://cdn.example.org/x.png" alt="第三方圖片"><img src="http://cms.mysite.localhost:8081/wp-content/uploads/d.png" alt="CMS 圖片"></p>',
            'cover' => ['url' => 'https://cdn.example.org/cover.png', 'alt' => '第三方封面', 'width' => 1200, 'height' => 630],
        ], true), ['preview' => true, 'snapshot_id' => str_repeat('a1', 16), 'revision_id' => 456], $override);
    }

    private function fakePreviewWp(array $snapshot, int $status = 200): void
    {
        $this->fakeWordPress([
            '/preview/*/*' => fn () => $status === 200 ? self::detailEnvelope($snapshot) : Http::response(['code' => 'site_content_preview_invalid'], $status),
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);
    }

    private function assertPreviewHeaders(TestResponse $response): void
    {
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    private function exchange(string $ticket): TestResponse
    {
        return $this->get('/preview/exchange?ticket='.$ticket);
    }

    private function cookieFrom(TestResponse $response): string
    {
        $cookie = $response->getCookie('mysite_preview', true);
        $this->assertNotNull($cookie, '必須設定 mysite_preview cookie');

        return (string) $cookie->getValue();
    }

    #[Test]
    #[Group('API-11')]
    #[Group('API-13')]
    public function exchange_consumes_the_ticket_sets_a_scoped_cookie_and_strips_the_url(): void
    {
        $ticket = self::previewTicket();

        $response = $this->exchange($ticket)->assertStatus(302);

        $location = (string) $response->headers->get('Location');
        $this->assertSame('/preview/view', parse_url($location, PHP_URL_PATH));
        $this->assertNull(parse_url($location, PHP_URL_QUERY), '導向網址不帶任何參數');
        $this->assertStringNotContainsString('ticket', (string) $response->headers->get('Location'));
        $this->assertPreviewHeaders($response);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'mysite_preview');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/preview', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame((bool) config('session.secure'), $cookie->isSecure());
        $this->assertSame(now()->getTimestamp() + 600, $cookie->getExpiresTime());
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')), '預覽不建立一般 Session');

        $claims = json_decode(base64_decode(strtr(explode('.', $ticket)[1], '-_', '+/')), true);
        $use = DB::table('preview_ticket_uses')->sole();
        $this->assertSame($claims['jti'], $use->jti);
        $session = DB::table('preview_sessions')->sole();
        $this->assertSame(64, strlen($session->token_hash));
        $this->assertStringNotContainsString($ticket, $session->ticket_ciphertext, '票券加密保存');
        $this->assertSame($ticket, decrypt($session->ticket_ciphertext, false));
        $this->assertStringNotContainsString($ticket, implode("\n", $this->logged), 'log 不記票券');
    }

    #[Test]
    #[Group('API-11')]
    public function replayed_tickets_are_rejected(): void
    {
        $ticket = self::previewTicket();
        $this->exchange($ticket)->assertStatus(302);

        $response = $this->exchange($ticket)->assertStatus(403);
        $response->assertSee('預覽已失效')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->assertPreviewHeaders($response);
        $this->assertEmpty($response->headers->getCookies());
        $this->assertSame(1, DB::table('preview_sessions')->count());
        $this->assertStringNotContainsString($ticket, implode("\n", $this->logged));
    }

    #[Test]
    #[Group('API-11')]
    public function concurrent_exchange_of_the_same_ticket_succeeds_only_once(): void
    {
        $ticket = self::previewTicket();
        $service = $this->app->make(PreviewService::class);
        // 第一個請求：在測試交易內寫入（尚未提交，等同「進行中」）
        $first = $service->exchange($ticket);
        $this->assertSame(64, strlen($first['token']));

        // 第二個請求：另一條資料庫連線同時交換同一張票券 → 被 jti 唯一鍵鎖住／拒絕，不能成功
        config(['database.connections.mysql_concurrent' => config('database.connections.mysql')]);
        $original = config('database.default');
        config(['database.default' => 'mysql_concurrent']);
        DB::connection('mysql_concurrent')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $service->exchange($ticket);
            $this->fail('並發的第二次交換不可成功');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        } finally {
            DB::connection('mysql_concurrent')->rollBack(0);
            DB::purge('mysql_concurrent');
            config(['database.default' => $original]);
        }

        // 第一個請求「提交」後再試：唯一鍵衝突 → 重播
        $this->expectException(PreviewInvalidException::class);
        $service->exchange($ticket);
    }

    #[Test]
    #[Group('API-11')]
    public function invalid_tickets_are_rejected_without_creating_sessions(): void
    {
        $now = now()->getTimestamp();
        $cases = [
            '缺票券' => '',
            '過期' => self::previewTicket(['iat' => $now - 700, 'exp' => $now - 100]),
            '竄改簽章' => self::previewTicket([], 'other-key'),
            '錯誤 audience' => self::previewTicket(['aud' => 'someone-else']),
            '錯誤 issuer' => self::previewTicket(['iss' => 'evil']),
            'TTL 超過設定' => self::previewTicket(['iat' => $now, 'exp' => $now + 601]),
            '格式錯誤' => 'not-a-ticket',
        ];
        foreach ($cases as $label => $ticket) {
            $response = $this->exchange($ticket)->assertStatus(403);
            $this->assertPreviewHeaders($response);
            $response->assertSee('預覽已失效');
            $this->assertSame(0, DB::table('preview_sessions')->count(), $label);
        }
        $this->get('/preview/exchange?ticket[]=x')->assertStatus(403);
    }

    #[Test]
    #[Group('API-11')]
    #[Group('API-12')]
    #[Group('API-13')]
    public function view_shows_the_exact_snapshot_with_preview_banner_and_no_third_party_resources(): void
    {
        $ticket = self::previewTicket(['ctype' => 'post', 'cid' => 123, 'rev' => 456]);
        $token = $this->cookieFrom($this->exchange($ticket));
        $this->fakePreviewWp($this->snapshot());

        $response = $this->withCookie('mysite_preview', $token)->get('/preview/view')->assertOk();

        $this->assertPreviewHeaders($response);
        $response->assertSee('草稿標題（尚未公開）')
            ->assertSee('草稿正文')
            ->assertSee('preview-banner', false)
            ->assertSee('修訂 #456')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<meta name="referrer" content="no-referrer">', false)
            ->assertSee('src="http://cms.mysite.localhost:8081/wp-content/uploads/d.png"', false)
            ->assertDontSee('cdn.example.org', false)
            ->assertDontSee('<link rel="canonical"', false);

        $this->assertCount(1, $this->wpCallsMatching('/preview/post/123?snapshot='.str_repeat('a1', 16)));
        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), self::WP_PRIVATE_BASE.'/preview/post/123')
            && $r->header('X-Mysite-Preview-Ticket') === [$ticket]
            && $r->header('Authorization') === ['Basic '.base64_encode('mysite-service:test-service-app-password')]);
        $this->assertSame([], $this->wpCallsMatching('/posts*'), '不讀公開端點替代');
        $this->assertNull($this->app->make(ContentCache::class)->body(ContentType::Post, 123), '預覽不寫入公開快取');
        $this->assertSame(0, $this->app->make(ContentCache::class)->generation());
        $this->assertStringNotContainsString($ticket, implode("\n", $this->logged));
    }

    #[Test]
    #[Group('API-13')]
    public function preview_of_a_video_does_not_embed_youtube(): void
    {
        $token = $this->cookieFrom($this->exchange(self::previewTicket(['ctype' => 'video', 'cid' => 123])));
        $this->fakePreviewWp($this->snapshot(['type' => 'video', 'video' => ['youtube_id' => 'dQw4w9WgXcQ', 'youtube_url' => null, 'duration_seconds' => 10]]));

        $this->withCookie('mysite_preview', $token)->get('/preview/view')->assertOk()
            ->assertSee('預覽模式不嵌入外部播放器')
            ->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ', false)
            ->assertDontSee('data-embed-src', false)
            ->assertDontSee('youtube-nocookie', false);
    }

    #[Test]
    #[Group('API-11')]
    #[Group('API-12')]
    public function wp_rejection_or_mismatched_snapshot_is_preview_invalid_never_the_public_article(): void
    {
        $cases = [
            'WP 403' => [$this->snapshot(), 403],
            'WP 404' => [$this->snapshot(), 404],
            '跨內容' => [$this->snapshot(['id' => 999]), 200],
            '錯誤快照' => [$this->snapshot(['snapshot_id' => str_repeat('b2', 16)]), 200],
            '錯誤 revision' => [$this->snapshot(['revision_id' => 455]), 200],
            '非預覽回應' => [$this->snapshot(['preview' => false]), 200],
            '型別不符' => [$this->snapshot(['type' => 'page']), 200],
        ];
        foreach ($cases as $label => [$snapshot, $status]) {
            $token = $this->cookieFrom($this->exchange(self::previewTicket()));
            $this->fakePreviewWp($snapshot, $status);

            $response = $this->withCookie('mysite_preview', $token)->get('/preview/view');
            $response->assertStatus(403);
            $this->assertPreviewHeaders($response);
            $response->assertSee('預覽已失效')->assertDontSee('草稿正文');
            $this->assertSame([], $this->wpCallsMatching('/posts*'), $label);
        }
    }

    #[Test]
    #[Group('API-11')]
    public function missing_unknown_or_expired_sessions_are_rejected(): void
    {
        $this->fakePreviewWp($this->snapshot());
        $this->get('/preview/view')->assertStatus(403);
        $this->withCookie('mysite_preview', str_repeat('0', 64))->get('/preview/view')->assertStatus(403);
        $this->withCookie('mysite_preview', 'not-hex')->get('/preview/view')->assertStatus(403);

        $token = $this->cookieFrom($this->exchange(self::previewTicket()));
        $this->travel(601)->seconds();
        $this->withCookie('mysite_preview', $token)->get('/preview/view')->assertStatus(403)->assertSee('預覽已失效');
        $this->assertSame([], $this->wpCallsMatching('/preview/*'), '過期 session 不呼叫 WP');
    }

    #[Test]
    #[Group('API-11')]
    #[Group('API-02')]
    public function wp_outage_during_preview_is_503(): void
    {
        $token = $this->cookieFrom($this->exchange(self::previewTicket()));
        $this->fakeWordPress(['/preview/*/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'), '/menus/*' => Http::response('', 404), '/site' => Http::response('', 404)]);

        $response = $this->withCookie('mysite_preview', $token)->get('/preview/view')->assertStatus(503);
        $this->assertPreviewHeaders($response);
    }

    #[Test]
    #[Group('API-13')]
    public function scheduled_pruning_removes_expired_preview_data_and_old_receipts(): void
    {
        $this->exchange(self::previewTicket())->assertStatus(302);
        DB::table('content_event_receipts')->insert([
            'event_id' => '44444444-4444-4444-8444-444444444444', 'key_id' => 'k1', 'body_sha256' => str_repeat('0', 64),
            'event_type' => 'content.updated', 'content_type' => 'post', 'content_id' => 1, 'content_version' => 1, 'status' => 'publish',
            'occurred_at' => now(), 'state' => 'processed', 'received_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('mysite:content-prune')->assertSuccessful();
        $this->assertSame(1, DB::table('preview_sessions')->count(), '未過期不刪');

        $this->travel(601)->seconds();
        $this->artisan('mysite:content-prune')->assertSuccessful();
        $this->assertSame(0, DB::table('preview_sessions')->count());
        $this->assertSame(1, DB::table('preview_ticket_uses')->count(), '票券使用紀錄多保留一個最大 TTL');

        $this->travel(31)->days();
        $this->artisan('mysite:content-prune')->assertSuccessful();
        $this->assertSame(0, DB::table('preview_ticket_uses')->count());
        $this->assertSame(0, DB::table('content_event_receipts')->count(), '事件收據保留 30 天');
    }
}
