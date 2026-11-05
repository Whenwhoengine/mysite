<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * 稽核 metadata 去秘密化：遮罩可能含秘密的鍵、截斷過長字串、限制巢狀深度與項目數。
 */
final class MetadataRedactor
{
    public const MASK = '[已遮罩]';

    /** 鍵名含這些片段即遮罩（不分大小寫）。 */
    private const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'key', 'recovery', 'two_factor', 'otp', 'code',
        'card', 'cvv', 'cvc', 'pan', 'iban', 'authorization', 'cookie', 'session', 'credential',
        'phone', 'address', 'email',
    ];

    /** 雖含敏感片段但經過審查可保留的鍵（內容不含秘密）。 */
    private const ALLOWED = ['reason', 'sku', 'status', 'role', 'email_hash', 'product_id', 'variant_id', 'order_id', 'user_id'];

    private const MAX_STRING = 200;

    private const MAX_ITEMS = 50;

    private const MAX_DEPTH = 4;

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        $out = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $out['_truncated'] = true;
                break;
            }
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::MASK;

                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => $depth >= self::MAX_DEPTH ? '[巢狀過深]' : self::redact($value, $depth + 1),
                is_string($value) => mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value,
                is_int($value), is_bool($value), $value === null => $value,
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \Stringable => mb_substr((string) $value, 0, self::MAX_STRING),
                is_float($value) => (string) $value,
                default => '['.get_debug_type($value).']',
            };
        }

        return $out;
    }

    public static function isSensitive(string $key): bool
    {
        $k = strtolower($key);
        if (in_array($k, self::ALLOWED, true)) {
            return false;
        }
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }

        return false;
    }
}
