<?php

declare(strict_types=1);

namespace App\Domain\Content\Http;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Throwable;

/**
 * WP 上游錯誤（API-02）：reason 為內部分類，只進受限 log；訪客只看到 ErrorCode 的固定訊息。
 *
 * reason：not_found、connect、timeout、http_5xx、invalid_json、too_large、redirect、auth、
 *         client_error、unexpected_status、url_blocked、misconfigured
 */
final class UpstreamException extends MysiteException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        $code = $reason === 'not_found' ? ErrorCode::ContentNotFound : ErrorCode::ContentUpstreamUnavailable;
        parent::__construct($code, 'content_upstream:'.$reason.($upstreamStatus !== null ? ':'.$upstreamStatus : ''), $previous);
    }

    /** 是否屬於「上游暫時不可用」（用於當次請求的後續呼叫直接改用後備資料）。 */
    public function isAvailabilityFailure(): bool
    {
        return in_array($this->reason, ['connect', 'timeout', 'http_5xx', 'invalid_json', 'too_large', 'redirect', 'unexpected_status'], true);
    }
}
