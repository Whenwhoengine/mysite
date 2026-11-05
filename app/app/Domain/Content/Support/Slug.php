<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

/**
 * slug 的兩種形式：路由取得的「解碼後」字串，以及送往 WP 的「API 形式」（契約 §3.1：[a-z0-9-]，
 * 中文 slug 為小寫 URL 編碼 [%a-z0-9-]，長度 ≤ 200）。不合格式即視為不存在，不送往上游。
 */
final class Slug
{
    public static function toApi(string $decoded): ?string
    {
        if ($decoded === '' || ! mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }
        if (preg_match('/^[a-z0-9-]{1,190}$/D', $decoded) === 1) {
            return $decoded;
        }
        // 已編碼形式直接傳入（例如 %e4%b8%ad）
        if (preg_match('/^[%a-z0-9-]{1,200}$/D', $decoded) === 1 && rawurldecode($decoded) !== $decoded) {
            return mb_check_encoding(rawurldecode($decoded), 'UTF-8') ? $decoded : null;
        }
        // 純 ASCII 只能符合第一條規則；其餘（含大寫、底線、斜線、空白等）一律不存在
        if (preg_match('/^[\x00-\x7f]*$/D', $decoded) === 1 || preg_match('/[A-Z\s\/\\\\?#&=+_.%]/', $decoded) === 1) {
            return null;
        }
        $encoded = strtolower(rawurlencode($decoded));

        return preg_match('/^[%a-z0-9-]{1,200}$/D', $encoded) === 1 ? $encoded : null;
    }

    /** API 形式 → 顯示／產生網址用的解碼字串。 */
    public static function fromApi(string $apiSlug): string
    {
        $decoded = rawurldecode($apiSlug);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $apiSlug;
    }
}
