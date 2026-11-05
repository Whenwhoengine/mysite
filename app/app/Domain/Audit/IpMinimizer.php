<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * IP 最小化：IPv4 只留 /24（末段歸零），IPv6 只留 /48。無法解析時不保存。
 */
final class IpMinimizer
{
    public static function minimize(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts).'/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $masked = substr($packed, 0, 6).str_repeat("\0", 10);
            $text = inet_ntop($masked);

            return $text === false ? null : $text.'/48';
        }

        return null;
    }
}
