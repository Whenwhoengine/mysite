<?php

declare(strict_types=1);

namespace App\Domain\Preview;

use App\Domain\Content\ContentType;
use App\Support\Secrets\KeyRing;

/**
 * 預覽票券驗證（ADR-008、API-11）：
 * base64url(header).base64url(claims).base64url(HMAC-SHA256(key, header.claims))，無 padding。
 * header 為 {"alg":"HS256","typ":"mysite-preview"}，或加上 key ID 的 {"alg":"HS256","typ":"mysite-preview","kid":"k2"}
 * （契約 §7 擴充）；驗 iss／aud／typ／exp、exp-iat ≤ min(PREVIEW_TTL, 900)。
 *
 * 金鑰圈（GAP-KEYRING-01）：有 kid 時依 kid 找金鑰（active 或 accept）；kid 不在金鑰圈（含已 retire 的舊 key）→ unknown_kid；
 * kid 格式不合法 → header；沒有 kid 的舊格式票券只以 active key 驗證。
 */
final class PreviewTicketVerifier
{
    /** 票券字串長度上限（暫定值，見完工報告）。 */
    public const MAX_LENGTH = 4096;

    /** iat 允許比本機時鐘超前的秒數（容器間時鐘誤差；暫定值，見完工報告）。 */
    public const IAT_LEEWAY_SECONDS = 30;

    public const HARD_MAX_TTL = 900;

    /** 沒有金鑰圈時，單一預覽金鑰的 key ID（與 WP 端一致）。 */
    public const DEFAULT_KEY_ID = 'k1';

    private readonly KeyRing $keyRing;

    public function __construct(
        #[\SensitiveParameter] string $key,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $ttl,
        private readonly int $maxTtl,
        ?KeyRing $keyRing = null,
    ) {
        $this->keyRing = $keyRing ?? KeyRing::single(self::DEFAULT_KEY_ID, $key);
    }

    public static function fromKeyRing(KeyRing $keyRing, string $issuer, string $audience, int $ttl, int $maxTtl): self
    {
        return new self('', $issuer, $audience, $ttl, $maxTtl, $keyRing);
    }

    public function keyRing(): KeyRing
    {
        return $this->keyRing;
    }

    /**
     * @throws PreviewInvalidException
     */
    public function verify(#[\SensitiveParameter] string $ticket, int $now): PreviewClaims
    {
        if ($this->keyRing->activeKey() === '') {
            throw new PreviewInvalidException('signing_key_missing');
        }
        if (strlen($ticket) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $ticket) !== 1) {
            throw new PreviewInvalidException('format');
        }
        [$h, $c, $s] = explode('.', $ticket);

        // 先只讀 header 的 kid 以選擇金鑰；其餘 header 欄位在驗簽通過後才檢查
        $header = self::json($h);
        $kid = null;
        if ($header !== null && array_key_exists('kid', $header)) {
            if (! is_string($header['kid']) || preg_match(KeyRing::KEY_ID_PATTERN, $header['kid']) !== 1) {
                throw new PreviewInvalidException('header');
            }
            $kid = $header['kid'];
        }
        $key = $kid === null ? $this->keyRing->activeKey() : $this->keyRing->find($kid);
        if ($key === null || $key === '') {
            throw new PreviewInvalidException('unknown_kid');
        }

        $expected = self::b64url(hash_hmac('sha256', $h.'.'.$c, $key, true));
        if (! hash_equals($expected, $s)) {
            throw new PreviewInvalidException('signature');
        }

        if ($header === null || count($header) !== ($kid === null ? 2 : 3) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'mysite-preview') {
            throw new PreviewInvalidException('header');
        }
        $claims = self::json($c);
        if ($claims === null) {
            throw new PreviewInvalidException('claims');
        }
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new PreviewInvalidException('iss');
        }
        if (($claims['aud'] ?? null) !== $this->audience) {
            throw new PreviewInvalidException('aud');
        }
        if (($claims['typ'] ?? null) !== 'preview') {
            throw new PreviewInvalidException('typ');
        }
        $ctype = is_string($claims['ctype'] ?? null) ? ContentType::tryFrom($claims['ctype']) : null;
        foreach (['cid', 'rev', 'iat', 'exp', 'sub'] as $int) {
            if (! is_int($claims[$int] ?? null) || $claims[$int] < 0) {
                throw new PreviewInvalidException('claims_'.$int);
            }
        }
        if ($ctype === null || $claims['cid'] < 1 || $claims['sub'] < 1
            || ! is_string($claims['sid'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $claims['sid']) !== 1
            || ! is_string($claims['jti'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $claims['jti']) !== 1) {
            throw new PreviewInvalidException('claims');
        }
        $lifetime = $claims['exp'] - $claims['iat'];
        if ($lifetime <= 0 || $lifetime > min($this->ttl, $this->maxTtl, self::HARD_MAX_TTL)) {
            throw new PreviewInvalidException('ttl');
        }
        if ($claims['iat'] > $now + self::IAT_LEEWAY_SECONDS) {
            throw new PreviewInvalidException('iat_future');
        }
        if ($claims['exp'] <= $now) {
            throw new PreviewInvalidException('expired');
        }

        return new PreviewClaims(
            $claims['iss'], $claims['aud'], $ctype, $claims['cid'], $claims['sid'], $claims['rev'],
            $claims['iat'], $claims['exp'], $claims['jti'], $claims['sub'],
        );
    }

    /** 測試與 WP 端共用的簽發格式（Laravel 端只在測試使用）；帶 kid 時把 ['kid' => …] 併入 header 傳入。 */
    public static function issue(array $claims, #[\SensitiveParameter] string $key, ?array $header = null): string
    {
        $h = self::b64url((string) json_encode($header ?? ['alg' => 'HS256', 'typ' => 'mysite-preview']));
        $c = self::b64url((string) json_encode($claims));

        return $h.'.'.$c.'.'.self::b64url(hash_hmac('sha256', $h.'.'.$c, $key, true));
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    private static function json(string $part): ?array
    {
        $raw = base64_decode(strtr($part, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
