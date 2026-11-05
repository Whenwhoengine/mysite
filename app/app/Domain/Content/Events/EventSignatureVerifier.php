<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

/**
 * 內容事件 HMAC 驗證（ADR-008、API-06）。
 *
 * 簽署字串：delivery_timestamp + "\n" + event_id + "\n" + raw_body（原始位元組）；
 * HMAC-SHA256、小寫 hex、hash_equals 常數時間比較；時差窗 event_clock_skew（300 秒）。
 */
final class EventSignatureVerifier
{
    public function __construct(
        private readonly string $keyId,
        private readonly string $key,
        private readonly int $clockSkewSeconds,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->key !== '' && $this->keyId !== '';
    }

    /**
     * @return string|null 失敗原因（只進 log，不回給呼叫端）；null 表示驗證通過
     */
    public function failure(?string $keyId, ?string $timestamp, ?string $eventId, ?string $signature, string $rawBody, int $now): ?string
    {
        if ($keyId === null || $keyId === '' || $timestamp === null || $timestamp === '' || $eventId === null || $eventId === '' || $signature === null || $signature === '') {
            return 'missing_header';
        }
        if (! hash_equals($this->keyId, $keyId)) {
            return 'unknown_key_id';
        }
        if (preg_match('/^\d{1,12}$/D', $timestamp) !== 1) {
            return 'timestamp_format';
        }
        if (abs($now - (int) $timestamp) > $this->clockSkewSeconds) {
            return 'timestamp_skew';
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            return 'signature_format';
        }
        if (! hash_equals(self::sign($this->key, $timestamp, $eventId, $rawBody), $signature)) {
            return 'signature_mismatch';
        }

        return null;
    }

    public static function sign(string $key, string $timestamp, string $eventId, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp."\n".$eventId."\n".$rawBody, $key);
    }
}
