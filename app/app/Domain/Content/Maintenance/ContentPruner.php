<?php

declare(strict_types=1);

namespace App\Domain\Content\Maintenance;

use App\Domain\Content\Cache\ContentCache;
use Illuminate\Support\Facades\DB;

/**
 * 排程清理（routes/console-content.php；只在 scheduler 執行，不在 Web request 內）：
 * - 過期的 preview session 與已過期票券的使用紀錄；
 * - 超過保存期限的事件收據（預設 30 天）；
 * - database 快取中已過期的內容快取列（搜尋等鍵不會再被讀到，需主動清除）。
 */
final class ContentPruner
{
    public const RECEIPT_RETENTION_DAYS = 30;

    /** 每批刪除筆數（避免長交易）。 */
    private const CHUNK = 1000;

    /** @return array{preview_sessions: int, preview_ticket_uses: int, content_event_receipts: int, content_cache_rows: int} */
    public function prune(): array
    {
        $now = now();
        $maxTicketTtl = max(60, (int) config('mysite.preview.max_ttl', 900));

        return [
            'preview_sessions' => $this->deleteWhere('preview_sessions', 'expires_at', $now),
            // 票券過期後重播本來就會被 exp 拒絕；多保留一個最大 TTL 作時鐘誤差緩衝
            'preview_ticket_uses' => $this->deleteWhere('preview_ticket_uses', 'expires_at', $now->subSeconds($maxTicketTtl)),
            'content_event_receipts' => $this->deleteWhere('content_event_receipts', 'created_at', $now->subDays(self::RECEIPT_RETENTION_DAYS)),
            'content_cache_rows' => $this->pruneDatabaseCache($now->getTimestamp()),
        ];
    }

    private function deleteWhere(string $table, string $column, \DateTimeInterface $before): int
    {
        $total = 0;
        do {
            $deleted = DB::table($table)->where($column, '<', $before)->orderBy($column)->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }

    private function pruneDatabaseCache(int $now): int
    {
        $store = (string) config('cache.default');
        if (config("cache.stores.{$store}.driver") !== 'database') {
            return 0;
        }
        $table = (string) config("cache.stores.{$store}.table", 'cache');
        $connection = config("cache.stores.{$store}.connection");
        $prefix = (string) config('cache.prefix', '');
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix.ContentCache::PREFIX).'%';

        $total = 0;
        do {
            $deleted = DB::connection($connection)->table($table)
                ->where('key', 'like', $like)
                ->where('expiration', '<', $now)
                ->limit(self::CHUNK)
                ->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
