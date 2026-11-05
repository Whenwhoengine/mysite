<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

use Carbon\CarbonImmutable;

/**
 * 時間與長度的顯示格式：資料一律以 UTC 解析，顯示時轉 mysite.display_timezone（Asia/Taipei）。
 */
final class DisplayTime
{
    public static function date(CarbonImmutable $utc): string
    {
        return $utc->setTimezone(self::zone())->format('Y年n月j日');
    }

    public static function dateTime(CarbonImmutable $utc): string
    {
        return $utc->setTimezone(self::zone())->format('Y年n月j日 H:i');
    }

    /** `<time datetime>` 用的 ISO 8601（UTC）。 */
    public static function iso(CarbonImmutable $utc): string
    {
        return $utc->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
    }

    /** 片長：1:02:03 或 4:05。 */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** 片長的口語說明（螢幕閱讀器）：1 小時 2 分 3 秒。 */
    public static function durationSpoken(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        $parts = [];
        if ($h > 0) {
            $parts[] = $h.' 小時';
        }
        if ($m > 0) {
            $parts[] = $m.' 分';
        }
        if ($s > 0 && $h === 0) {
            $parts[] = $s.' 秒';
        }

        return implode(' ', $parts);
    }

    /** ISO 8601 期間（PT1H2M3S）。 */
    public static function isoDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h > 0 ? $h.'H' : '').($m > 0 ? $m.'M' : '').($s > 0 ? $s.'S' : '');
    }

    private static function zone(): string
    {
        return (string) config('mysite.display_timezone', 'Asia/Taipei');
    }
}
