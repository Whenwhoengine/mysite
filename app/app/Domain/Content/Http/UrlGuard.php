<?php

declare(strict_types=1);

namespace App\Domain\Content\Http;

/**
 * 上游網址 allowlist（API-03、API-04）。
 *
 * - 只允許 http／https，不允許帳密（userinfo）。
 * - host 必須逐字列在 mysite.content.allowed_hosts；這是「固定 WP 來源」的私網例外，只適用這些 host。
 * - metadata 位址（169.254.0.0/16、fd00:ec2::254、metadata.google.internal 等）即使被列入也一律拒絕。
 * - 其他任何網址（私網 IP、保留位址、非 allowlist host）都拒絕；redirect 一律不跟隨（由 client 處理）。
 */
final class UrlGuard
{
    private const METADATA_HOSTS = ['metadata.google.internal', 'metadata', 'instance-data', 'instance-data.ec2.internal'];

    /** @var list<string> */
    private readonly array $allowedHosts;

    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(array $allowedHosts)
    {
        $this->allowedHosts = array_values(array_unique(array_map(static fn ($h): string => strtolower(trim((string) $h)), $allowedHosts)));
    }

    /**
     * @throws UpstreamException
     */
    public function assertAllowed(string $url): void
    {
        $reason = $this->rejectionReason($url);
        if ($reason !== null) {
            throw new UpstreamException('url_blocked', null, new \InvalidArgumentException($reason));
        }
    }

    public function rejectionReason(string $url): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return 'invalid_characters';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return 'unparsable';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'scheme';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'userinfo';
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            return 'host_missing';
        }
        if (self::isMetadataHost($host)) {
            return 'metadata';
        }
        if (! in_array($host, $this->allowedHosts, true)) {
            return self::isPrivateOrReservedIp($host) ? 'private_network' : 'host_not_allowed';
        }

        return null;
    }

    public static function isMetadataHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if (in_array($host, self::METADATA_HOSTS, true)) {
            return true;
        }
        $ip = self::normalizeIp($host);
        if ($ip === null) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // 169.254.0.0/16（雲端 metadata、link-local）與 100.100.100.200（部分雲端 metadata）
            return str_starts_with($ip, '169.254.') || $ip === '100.100.100.200';
        }

        $packed = inet_pton($ip);

        return $packed === inet_pton('fd00:ec2::254') || str_starts_with(strtolower($ip), 'fe80:');
    }

    public static function isPrivateOrReservedIp(string $host): bool
    {
        $ip = self::normalizeIp($host);
        if ($ip === null) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** IP 字面值（含 IPv4 十進位／十六進位簡寫）正規化；非 IP 回 null。 */
    private static function normalizeIp(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        // 例如 2852039166（= 169.254.169.254）、0xa9fea9fe、0251.0376.0251.0376
        if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}$/i', $host) === 1) {
            $long = ip2long($host);
            if ($long === false) {
                $parts = explode('.', $host);
                if (count($parts) === 1) {
                    $value = str_starts_with(strtolower($host), '0x') ? hexdec($host) : (ctype_digit($host) && $host[0] === '0' && strlen($host) > 1 ? octdec($host) : (int) $host);
                    if ($value >= 0 && $value <= 0xFFFFFFFF) {
                        return long2ip((int) $value);
                    }

                    return null;
                }
                $octets = [];
                foreach ($parts as $p) {
                    $octets[] = str_starts_with(strtolower($p), '0x') ? hexdec($p) : (strlen($p) > 1 && $p[0] === '0' ? octdec($p) : (int) $p);
                }
                if (count($octets) === 4 && max($octets) <= 255) {
                    return implode('.', array_map('intval', $octets));
                }

                return null;
            }

            return long2ip($long);
        }

        return null;
    }
}
