<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 本案固定錯誤碼與 HTTP 狀態（docs/API契約.md §6）。
 */
enum ErrorCode: string
{
    case ContentNotFound = 'CONTENT_NOT_FOUND';
    case ContentUpstreamUnavailable = 'CONTENT_UPSTREAM_UNAVAILABLE';
    case ContentModelUnsupported = 'CONTENT_MODEL_UNSUPPORTED';
    case PreviewInvalid = 'PREVIEW_INVALID';
    case EventSignatureInvalid = 'EVENT_SIGNATURE_INVALID';
    case RateLimited = 'RATE_LIMITED';
    case CommerceDisabled = 'COMMERCE_DISABLED';
    case SimulationOnly = 'SIMULATION_ONLY';

    public function status(): int
    {
        return match ($this) {
            self::ContentNotFound, self::CommerceDisabled => 404,
            self::ContentUpstreamUnavailable, self::ContentModelUnsupported => 503,
            self::PreviewInvalid => 403,
            self::EventSignatureInvalid => 401,
            self::RateLimited => 429,
            self::SimulationOnly => 422,
        };
    }

    /** 給訪客看的正體中文訊息（不揭露內部細節）。 */
    public function publicMessage(): string
    {
        return match ($this) {
            self::ContentNotFound => '找不到這個內容。',
            self::ContentUpstreamUnavailable => '內容暫時無法讀取，請稍後再試。',
            self::ContentModelUnsupported => '內容暫時無法顯示，請稍後再試。',
            self::PreviewInvalid => '預覽連結已失效，請回到編輯器重新產生預覽。',
            self::EventSignatureInvalid => '簽章驗證失敗。',
            self::RateLimited => '操作過於頻繁，請稍後再試。',
            self::CommerceDisabled => '找不到這個頁面。',
            self::SimulationOnly => '此功能僅供模擬，不接受真實交易。',
        };
    }
}
