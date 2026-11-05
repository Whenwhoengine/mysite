<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Support\Secrets\KeyRing;

/**
 * 內容事件 HMAC 驗證（ADR-008、API-06）。
 *
 * 簽署字串：delivery_timestamp + "\n" + event_id + "\n" + raw_body（原始位元組）；
 * HMAC-SHA256、小寫 hex、hash_equals 常數時間比較；時差窗 event_clock_skew（300 秒）。
 *
 * 金鑰圈（GAP-KEYRING-01）：依 X-Mysite-Key-Id 在金鑰圈中找金鑰（active 或 accept 皆可驗證）；
 * key ID 不在金鑰圈（含已 retire 的舊 key）→ unknown_key_id。缺 key ID 仍屬 missing_header（契約 §4 必填）。
 * 沒有金鑰圈時以 (keyId, key) 組成單一金鑰圈，行為與原本相同。
 */
final class EventSignatureVerifier
{
    private readonly KeyRing $keyRing;

    public function __construct(
        string $keyId,
        #[\SensitiveParameter] string $key,
        private readonly int $clockSkewSeconds,
        ?KeyRing $keyRing = null,
    ) {
        $this->keyRing = $keyRing ?? KeyRing::single($keyId, $key);
    }

    public static function fromKeyRing(KeyRing $keyRing, int $clockSkewSeconds): self
    {
        return new self('', '', $clockSkewSeconds, $keyRing);
    }

    public function keyRing(): KeyRing
    {
        return $this->keyRing;
    }

    public function isConfigured(): bool
    {
        return $this->keyRing->activeKey() !== '' && $this->keyRing->activeId() !== '';
    }

    /**
     * @return string|null 失敗原因（只進 log，不回給呼叫端）；null 表示驗證通過
     */
    public function failure(?string $keyId, ?string $timestamp, ?string $eventId, ?string $signature, string $rawBody, int $now): ?string
    {
        if ($keyId === null || $keyId === '' || $timestamp === null || $timestamp === '' || $eventId === null || $eventId === '' || $signature === null || $signature === '') {
            return 'missing_header';
        }
        $key = $this->keyRing->find($keyId);
        if ($key === null || $key === '') {
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
        if (! hash_equals(self::sign($key, $timestamp, $eventId, $rawBody), $signature)) {
            return 'signature_mismatch';
        }

        return null;
    }

    public static function sign(#[\SensitiveParameter] string $key, string $timestamp, string $eventId, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp."\n".$eventId."\n".$rawBody, $key);
    }
}
