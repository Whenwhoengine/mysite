<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Domain\Content\ContentType;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Illuminate\Http\Request;

/**
 * 公開頁與公開 API 共用的查詢參數規則（MySite React 前端專案 PAPI-07：兩邊對不合法參數的回應一致）。
 */
final class PublicContentInput
{
    public const SEARCH_MAX_LENGTH = 100;

    /** page 參數：1–1000 的正整數；其他值一律 404（不送往上游，canonical 唯一）。 */
    public static function page(Request $request): int
    {
        $raw = $request->query('page');
        if ($raw === null) {
            return 1;
        }
        if (! is_string($raw) || preg_match('/^[1-9]\d{0,3}$/D', $raw) !== 1 || (int) $raw > 1000) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'page_param');
        }

        return (int) $raw;
    }

    /**
     * 搜尋參數：q 去頭尾空白並合併連續空白；type 不認得或 q 超過上限時給出正體中文錯誤（HTTP 422）。
     *
     * @return array{q: string, type: ?ContentType, page: int, error: ?string}
     */
    public static function search(Request $request): array
    {
        $rawQ = $request->query('q');
        $q = is_string($rawQ) ? trim((string) preg_replace('/\s+/u', ' ', $rawQ)) : '';
        $rawType = $request->query('type');
        $type = is_string($rawType) && $rawType !== '' ? ContentType::tryFrom($rawType) : null;
        $page = self::page($request);

        $error = null;
        if (is_string($rawType) && $rawType !== '' && $type === null) {
            $error = '內容類型不正確，請重新選擇。';
        } elseif (mb_strlen($q) > self::SEARCH_MAX_LENGTH) {
            $error = '搜尋字詞最多 '.self::SEARCH_MAX_LENGTH.' 個字，請縮短後再試。';
        }

        return ['q' => $q, 'type' => $type, 'page' => $page, 'error' => $error];
    }
}
