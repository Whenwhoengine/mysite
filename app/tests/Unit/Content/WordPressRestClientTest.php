<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\Http\UpstreamException;
use App\Domain\Content\Http\WordPressRestClient;
use App\Support\Errors\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * API-02（錯誤分類）、API-03（大小／路徑有界）、API-04（redirect／私網／metadata）、API-16（重試有界）。
 */
final class WordPressRestClientTest extends TestCase
{
    use FakesWordPress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        Sleep::fake();
    }

    private function client(): WordPressRestClient
    {
        return $this->app->make(WordPressRestClient::class);
    }

    private function capture(callable $call): UpstreamException
    {
        try {
            $call();
        } catch (UpstreamException $e) {
            return $e;
        }
        $this->fail('預期 UpstreamException');
    }

    #[Test]
    #[Group('API-01')]
    #[Group('API-02')]
    public function it_decodes_successful_responses_and_sends_bounded_query(): void
    {
        Http::fake([self::WP_BASE.'/posts*' => Http::response(self::listEnvelope([]), 200)]);

        $json = $this->client()->getPublic('/posts', ['page' => 2, 'per_page' => 12, 'category' => null, 'tag' => '']);

        $this->assertSame(1, $json['meta']['model_version']);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::WP_BASE.'/posts?page=2&per_page=12'
            && $r->method() === 'GET'
            && $r->header('Accept') === ['application/json']
            && ! $r->hasHeader('Authorization'));
        Http::assertSentCount(1);
    }

    #[Test]
    #[Group('API-02')]
    public function not_found_is_classified_and_not_retried(): void
    {
        Http::fake([self::WP_BASE.'/*' => Http::response(['code' => 'site_content_not_found'], 404)]);

        $e = $this->capture(fn () => $this->client()->getPublic('/posts/9'));

        $this->assertSame('not_found', $e->reason);
        $this->assertSame(ErrorCode::ContentNotFound, $e->errorCode);
        $this->assertSame(404, $e->errorCode->status());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-16')]
    public function server_errors_are_retried_once_then_fail_with_503(): void
    {
        Http::fake([self::WP_BASE.'/*' => Http::sequence()->push(['x' => 1], 502)->push(['x' => 1], 503)->push(self::listEnvelope([]), 200)]);

        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

        $this->assertSame('http_5xx', $e->reason);
        $this->assertSame(503, $e->upstreamStatus);
        $this->assertSame(ErrorCode::ContentUpstreamUnavailable, $e->errorCode);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        $this->assertTrue(request()->attributes->get('mysite.content_upstream_failed'));
    }

    #[Test]
    #[Group('API-16')]
    public function a_single_server_error_recovers_on_the_second_attempt(): void
    {
        Http::fake([self::WP_BASE.'/*' => Http::sequence()->push('', 500)->push(self::listEnvelope([]), 200)]);

        $this->assertSame([], $this->client()->getPublic('/videos')['data']);
        Http::assertSentCount(2);
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-16')]
    public function connection_failures_are_retried_once(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 7: Failed to connect to wp-web port 8080: Connection refused');
        });

        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

        $this->assertSame('connect', $e->reason);
        $this->assertSame(503, $e->errorCode->status());
        $this->assertSame(2, $attempts);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === WordPressRestClient::RETRY_BACKOFF_MS);
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-16')]
    public function total_timeouts_are_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received');
        });

        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

        $this->assertSame('timeout', $e->reason);
        $this->assertSame(1, $attempts);
        Sleep::assertNeverSlept();
    }

    #[Test]
    #[Group('API-02')]
    public function auth_errors_are_logged_as_configuration_errors_and_shown_as_503(): void
    {
        foreach ([401, 403] as $status) {
            Log::spy();
            $this->resetHttpFake();
            Http::fake([self::WP_BASE.'/*' => Http::response(['code' => 'site_content_forbidden'], $status)]);

            $e = $this->capture(fn () => $this->client()->getPublic('/taxonomies'));

            $this->assertSame('auth', $e->reason);
            $this->assertSame(503, $e->errorCode->status());
            Http::assertSentCount(1);
            Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'content_upstream_auth_error' && $context['status'] === $status);
        }
        Sleep::assertNeverSlept();
    }

    #[Test]
    #[Group('API-02')]
    public function client_errors_are_not_retried(): void
    {
        Http::fake([self::WP_BASE.'/*' => Http::response(['code' => 'site_content_invalid_param'], 400)]);

        $e = $this->capture(fn () => $this->client()->getPublic('/posts', ['page' => 1]));

        $this->assertSame('client_error', $e->reason);
        Http::assertSentCount(1);
    }

    #[Test]
    #[Group('API-02')]
    public function invalid_json_and_non_object_json_are_upstream_errors(): void
    {
        foreach (['<html>oops</html>', '[1,2,3]', '"text"', ''] as $body) {
            $this->resetHttpFake();
            Http::fake([self::WP_BASE.'/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

            $e = $this->capture(fn () => $this->client()->getPublic('/site'));

            $this->assertSame('invalid_json', $e->reason, '內容：'.$body);
            $this->assertSame(503, $e->errorCode->status());
        }
    }

    #[Test]
    #[Group('API-03')]
    public function oversized_responses_are_rejected(): void
    {
        config(['mysite.content.max_response_bytes' => 4096]);
        Http::fake([self::WP_BASE.'/*' => Http::response(json_encode(['data' => str_repeat('x', 5000), 'meta' => self::meta()]), 200)]);

        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

        $this->assertSame('too_large', $e->reason);
        $this->assertSame(503, $e->errorCode->status());
    }

    #[Test]
    #[Group('API-04')]
    public function redirects_are_never_followed(): void
    {
        foreach (['http://169.254.169.254/latest/meta-data/', 'http://10.0.0.8/', 'https://evil.example/'] as $location) {
            $requested = [];
            $this->resetHttpFake();
            Http::fake(function (HttpRequest $request) use (&$requested, $location) {
                $requested[] = $request->url();

                return Http::response('', 302, ['Location' => $location]);
            });

            $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

            $this->assertSame('redirect', $e->reason);
            $this->assertSame(302, $e->upstreamStatus);
            $this->assertSame([self::WP_BASE.'/posts'], $requested, '不可跟隨 redirect 到 '.$location);
        }
    }

    #[Test]
    #[Group('API-04')]
    public function configured_base_urls_outside_the_allowlist_never_send_requests(): void
    {
        Http::fake();
        foreach ([
            'http://169.254.169.254/wp-json/site-content/v1' => ['169.254.169.254'],
            'http://10.0.0.5/wp-json/site-content/v1' => ['wp-web'],
            'https://evil.example/wp-json/site-content/v1' => ['wp-web'],
            'file:///etc' => ['wp-web'],
        ] as $base => $allowed) {
            config(['mysite.content.api_base_url' => $base, 'mysite.content.allowed_hosts' => $allowed]);
            $this->app->forgetInstance(\App\Domain\Content\Http\UrlGuard::class);

            $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

            $this->assertSame('url_blocked', $e->reason, $base);
        }
        Http::assertNothingSent();
    }

    #[Test]
    #[Group('API-03')]
    public function request_paths_cannot_be_injected(): void
    {
        Http::fake();
        foreach (['/posts/../../wp-admin', '//evil.example/x', '/posts?x=1', '/posts#x', 'posts', '/posts/%2e%2e', '/POSTS', "/posts\n"] as $path) {
            $e = $this->capture(fn () => $this->client()->getPublic($path));
            $this->assertSame('url_blocked', $e->reason, $path);
        }
        Http::assertNothingSent();
    }

    #[Test]
    #[Group('API-16')]
    public function worst_case_wait_is_bounded_by_configuration(): void
    {
        $this->assertSame(10.1, $this->client()->worstCaseSeconds());

        config(['mysite.content.max_attempts' => 99]);
        $this->assertSame(10.1, $this->client()->worstCaseSeconds(), '嘗試次數硬上限 2');
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-16')]
    public function real_socket_total_timeout_is_bounded_and_not_retried(): void
    {
        // 真實 TCP：接受連線但永不回應 → 整體逾時（不重試），等待時間受 timeout 限制
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        Http::allowStrayRequests();
        config([
            'mysite.content.api_base_url' => "http://127.0.0.1:{$port}/wp-json/site-content/v1",
            'mysite.content.allowed_hosts' => ['127.0.0.1'],
            'mysite.content.timeout' => 1,
            'mysite.content.connect_timeout' => 1,
        ]);
        $this->app->forgetInstance(\App\Domain\Content\Http\UrlGuard::class);

        $started = microtime(true);
        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));
        $elapsed = microtime(true) - $started;
        fclose($server);

        $this->assertSame('timeout', $e->reason);
        $this->assertLessThan(2.5, $elapsed, '只嘗試一次且受 1 秒整體逾時限制');
        Sleep::assertNeverSlept();
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-16')]
    public function real_refused_connection_is_retried_exactly_once(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server); // 埠已關閉 → 連線被拒
        Http::allowStrayRequests();
        config([
            'mysite.content.api_base_url' => "http://127.0.0.1:{$port}/wp-json/site-content/v1",
            'mysite.content.allowed_hosts' => ['127.0.0.1'],
        ]);
        $this->app->forgetInstance(\App\Domain\Content\Http\UrlGuard::class);

        $e = $this->capture(fn () => $this->client()->getPublic('/posts'));

        $this->assertSame('connect', $e->reason);
        Sleep::assertSleptTimes(1);
    }

    #[Test]
    #[Group('API-02')]
    #[Group('API-11')]
    public function private_reads_require_https_credentials_and_ca(): void
    {
        Http::fake([self::WP_PRIVATE_BASE.'/*' => Http::response(self::detailEnvelope(['ok' => true]), 200)]);

        $this->client()->getPrivate('/preview/post/1', ['snapshot' => 'abc'], ['X-Mysite-Preview-Ticket' => 't.t.t']);

        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://wp-web:8443/')
            && $r->header('Authorization') === ['Basic '.base64_encode('mysite-service:test-service-app-password')]
            && $r->header('X-Mysite-Preview-Ticket') === ['t.t.t']
            && $r->url() === self::WP_PRIVATE_BASE.'/preview/post/1?snapshot=abc');

        foreach ([
            ['mysite.content.service_app_password' => null],
            ['mysite.content.private_api_ca_file' => '/nonexistent/ca.pem'],
            ['mysite.content.private_api_base_url' => 'http://wp-web:8080/wp-json/site-content/v1'],
        ] as $override) {
            $this->configureContent();
            config($override);
            $this->resetHttpFake();
            Http::fake();
            $e = $this->capture(fn () => $this->client()->getPrivate('/preview/post/1'));
            $this->assertSame('misconfigured', $e->reason);
            Http::assertNothingSent();
        }
    }

    #[Test]
    #[Group('API-11')]
    public function private_forbidden_is_distinct_from_configuration_errors(): void
    {
        Log::spy();
        Http::fake([self::WP_PRIVATE_BASE.'/*' => Http::response(['code' => 'site_content_preview_invalid'], 403)]);

        $e = $this->capture(fn () => $this->client()->getPrivate('/preview/post/1'));

        $this->assertSame('forbidden', $e->reason);
        Log::shouldNotHaveReceived('error');
    }
}
