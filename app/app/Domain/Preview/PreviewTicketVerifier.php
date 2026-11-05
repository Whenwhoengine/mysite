<?php

declare(strict_types=1);

namespace App\Domain\Preview;

use App\Domain\Content\ContentType;

/**
 * 預覽票券驗證（ADR-008、API-11）：
 * base64url(header).base64url(claims).base64url(HMAC-SHA256(key, header.claims))，無 padding。
 * header 固定 {"alg":"HS256","typ":"mysite-preview"}；驗 iss／aud／typ／exp、exp-iat ≤ min(PREVIEW_TTL, 900)。
 */
final class PreviewTicketVerifier
{
    /** 票券字串長度上限（暫定值，見完工報告）。 */
    public const MAX_LENGTH = 4096;

    /** iat 允許比本機時鐘超前的秒數（容器間時鐘誤差；暫定值，見完工報告）。 */
    public const IAT_LEEWAY_SECONDS = 30;

    public const HARD_MAX_TTL = 900;

    public function __construct(
        private readonly string $key,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $ttl,
        private readonly int $maxTtl,
    ) {}

    /**
     * @throws PreviewInvalidException
     */
    public function verify(string $ticket, int $now): PreviewClaims
    {
        if ($this->key === '') {
            throw new PreviewInvalidException('signing_key_missing');
        }
        if (strlen($ticket) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $ticket) !== 1) {
            throw new PreviewInvalidException('format');
        }
        [$h, $c, $s] = explode('.', $ticket);

        $expected = self::b64url(hash_hmac('sha256', $h.'.'.$c, $this->key, true));
        if (! hash_equals($expected, $s)) {
            throw new PreviewInvalidException('signature');
        }

        $header = self::json($h);
        if ($header === null || count($header) !== 2 || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'mysite-preview') {
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

    /** 測試與 WP 端共用的簽發格式（Laravel 端只在測試使用）。 */
    public static function issue(array $claims, string $key, ?array $header = null): string
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
