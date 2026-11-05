<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 在 MySQL 交易中執行，遇到死鎖／序列化失敗（SQLSTATE 40001、MySQL 1213／1205）做有限次重試。
 * 只在最外層交易重試：巢狀交易時 MySQL 已回復整個外層交易，重試沒有意義，直接拋出。
 */
final class TransactionRunner
{
    /** 最多嘗試次數（暫定：3 次）。 */
    public const MAX_ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(int $attempt): T  $callback
     * @return array{0: T, 1: int} [結果, 實際嘗試次數]
     */
    public static function run(Closure $callback, int $maxAttempts = self::MAX_ATTEMPTS): array
    {
        $detector = new ConcurrencyErrorDetector;
        $outermost = DB::transactionLevel() === 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                $result = DB::transaction(static fn () => $callback($attempt));

                return [$result, $attempt];
            } catch (Throwable $e) {
                if (! $outermost || $attempt >= $maxAttempts || ! $detector->causedByConcurrencyError($e)) {
                    throw $e;
                }
                // 退避（暫定：每次 10–40ms × 嘗試次數，含隨機抖動）
                usleep(random_int(10_000, 40_000) * $attempt);
            }
        }
    }
}
