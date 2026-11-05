<?php

declare(strict_types=1);

namespace App\Domain\Content\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * WordPress site-content/v1 唯一 HTTP 出口（ADR-007、v5 §8.3、API-02／03／04／16）。
 *
 * - 連線逾時 connect_timeout、整體逾時 timeout（每次嘗試各自受限）。
 * - 冪等 GET 最多 max_attempts 次嘗試；只對「連線失敗」與 5xx 重試；401／403／404／4xx、整體逾時、
 *   invalid JSON、回應過大、redirect 一律不重試。總等待上限 = max_attempts × timeout + 退避。
 * - 不跟隨 redirect（3xx 視為上游錯誤）；網址必須通過 UrlGuard；回應大小受 max_response_bytes 限制。
 * - 錯誤分類見 UpstreamException；Controller 不直接使用本類別。
 */
final class WordPressRestClient
{
    /** 重試前的固定退避（毫秒；暫定值，見完工報告暫定值清單）。 */
    public const RETRY_BACKOFF_MS = 100;

    /** @param array<string, mixed> $config config('mysite.content') */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly UrlGuard $guard,
        private readonly array $config,
    ) {
    }

    /**
     * 公開讀取（容器內固定 WP 來源）。
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws UpstreamException
     */
    public function getPublic(string $path, array $query = []): array
    {
        return $this->request((string) $this->config['api_base_url'], $path, $query, [], false);
    }

    /**
     * 服務帳號私密讀取（預覽）：只走 HTTPS＋本案 CA，帶 Basic auth（Application Password）。
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws UpstreamException
     */
    public function getPrivate(string $path, array $query = [], array $headers = []): array
    {
        $base = (string) $this->config['private_api_base_url'];
        if (parse_url($base, PHP_URL_SCHEME) !== 'https') {
            Log::error('content_upstream_misconfigured', ['reason' => 'private_api_not_https']);

            throw new UpstreamException('misconfigured');
        }
        $username = (string) ($this->config['service_username'] ?? '');
        $password = (string) ($this->config['service_app_password'] ?? '');
        $ca = (string) ($this->config['private_api_ca_file'] ?? '');
        if ($username === '' || $password === '' || $ca === '' || ! is_readable($ca)) {
            Log::error('content_upstream_misconfigured', ['reason' => 'service_credentials_or_ca_missing']);

            throw new UpstreamException('misconfigured');
        }

        return $this->request($base, $path, $query, $headers, true);
    }

    /** 一次請求在最壞情況下的總等待上限（秒）。 */
    public function worstCaseSeconds(): float
    {
        $attempts = $this->maxAttempts();

        return $attempts * (float) $this->config['timeout'] + ($attempts - 1) * self::RETRY_BACKOFF_MS / 1000;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function request(string $base, string $path, array $query, array $headers, bool $private): array
    {
        if (preg_match('#^/[a-z0-9/_-]{0,200}$#', $path) !== 1 || str_contains($path, '//')) {
            throw new UpstreamException('url_blocked', null, new \InvalidArgumentException('path'));
        }
        $url = rtrim($base, '/').$path;
        $this->guard->assertAllowed($url);
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');

        $attempts = $this->maxAttempts();
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->pending($private, $headers)->get($url, $query);
            } catch (Throwable $e) {
                $failure = $this->classifyTransport($e);
                if ($failure->reason === 'connect' && $attempt < $attempts) {
                    $this->backoff();

                    continue;
                }
                $this->markUnavailable();
                $this->logFailure($failure, $path, $attempt);

                throw $failure;
            }

            if ($response->status() >= 500 && $attempt < $attempts) {
                $this->backoff();

                continue;
            }

            try {
                return $this->decode($response);
            } catch (UpstreamException $e) {
                if ($e->isAvailabilityFailure()) {
                    $this->markUnavailable();
                }
                $this->logFailure($e, $path, $attempt);

                throw $e;
            }
        }
    }

    private function pending(bool $private, array $headers): PendingRequest
    {
        $max = $this->maxBytes();
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => (float) $this->config['connect_timeout'],
            'timeout' => (float) $this->config['timeout'],
            'read_timeout' => (float) $this->config['timeout'],
            'decode_content' => true,
            'on_headers' => static function (ResponseInterface $response) use ($max): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $max) {
                    throw new ResponseTooLarge('content-length');
                }
            },
            'progress' => static function ($downloadTotal, $downloaded) use ($max): void {
                if ((int) $downloaded > $max) {
                    throw new ResponseTooLarge('stream');
                }
            },
        ];
        if ($private) {
            $options['verify'] = (string) $this->config['private_api_ca_file'];
        }

        $request = $this->http->withOptions($options)
            ->withHeaders(array_merge(['Accept' => 'application/json', 'User-Agent' => 'mysite-app/1'], $headers));
        if ($private) {
            $request = $request->withBasicAuth((string) $this->config['service_username'], (string) $this->config['service_app_password']);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            throw new UpstreamException('redirect', $status);
        }
        if ($status === 404) {
            throw new UpstreamException('not_found', 404);
        }
        if ($status === 401 || $status === 403) {
            // 服務帳號、權限或 gateway 設定錯誤：記錄為設定錯誤，以 503 呈現
            Log::error('content_upstream_auth_error', ['status' => $status]);

            throw new UpstreamException('auth', $status);
        }
        if ($status >= 500) {
            throw new UpstreamException('http_5xx', $status);
        }
        if ($status >= 400) {
            throw new UpstreamException('client_error', $status);
        }
        if ($status !== 200) {
            throw new UpstreamException('unexpected_status', $status);
        }
        $body = $response->body();
        if (strlen($body) > $this->maxBytes()) {
            throw new UpstreamException('too_large', $status);
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UpstreamException('invalid_json', $status, $e);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UpstreamException('invalid_json', $status);
        }

        return $decoded;
    }

    private function classifyTransport(Throwable $e): UpstreamException
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ResponseTooLarge) {
                return new UpstreamException('too_large', null, $e);
            }
            if ($cause instanceof UpstreamException) {
                return $cause;
            }
        }
        $message = $e->getMessage();
        // 連線階段失敗（拒絕連線、無法解析、連線逾時）可重試；已連上後的整體逾時（Operation timed out）不重試
        $connectPhase = str_contains($message, 'Connection timed out')
            || str_contains($message, 'Connection refused')
            || str_contains($message, 'Failed to connect')
            || str_contains($message, 'Could not resolve')
            || str_contains($message, 'cURL error 6:')
            || str_contains($message, 'cURL error 7:');
        if (! $connectPhase && ($e instanceof ConnectionException || $e instanceof \GuzzleHttp\Exception\TransferException)
            && (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28:'))) {
            return new UpstreamException('timeout', null, $e);
        }

        return new UpstreamException('connect', null, $e);
    }

    private function logFailure(UpstreamException $e, string $path, int $attempts): void
    {
        // 只記路徑樣式與分類，不記查詢字串、header 或回應內容（可能含票券或草稿）
        Log::warning('content_upstream_failure', [
            'reason' => $e->reason,
            'status' => $e->upstreamStatus,
            'path' => preg_replace('#/\d+#', '/{id}', $path),
            'attempts' => $attempts,
        ]);
    }

    /** 當次 HTTP 請求已遇到上游不可用：版型的選單／品牌改用內建後備，避免同一頁重複等待逾時。 */
    private function markUnavailable(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->set('mysite.content_upstream_failed', true);
        }
    }

    private function backoff(): void
    {
        Sleep::usleep(self::RETRY_BACKOFF_MS * 1000);
    }

    private function maxAttempts(): int
    {
        return max(1, min(2, (int) ($this->config['max_attempts'] ?? 2)));
    }

    private function maxBytes(): int
    {
        return max(1024, (int) ($this->config['max_response_bytes'] ?? 2 * 1024 * 1024));
    }
}
