<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

use RuntimeException;

/**
 * 假資料匯出中止（MySite React 前端專案 F2 第 5 步）。
 *
 * 結束碼沿用 docs/執行契約.md 的 exit code 契約：
 * 10 參數錯；20 缺外部前置（開發相依套件）；30 功能失敗（API 非 200、契約不符、找不到有結果的搜尋）；
 * 40 禁止的安全操作（遇到非示例內容、在 staging／production 執行）。
 */
final class MockExportException extends RuntimeException
{
    public const USAGE = 10;

    public const EXTERNAL = 20;

    public const FAILED = 30;

    public const FORBIDDEN = 40;

    public function __construct(string $message, public readonly int $exitCode = self::FAILED)
    {
        parent::__construct($message);
    }

    public static function usage(string $message): self
    {
        return new self($message, self::USAGE);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, self::FORBIDDEN);
    }

    /** 遇到非示例內容：假資料只放示例內容（v1 §4.4），一律中止。 */
    public static function nonSample(string $where): self
    {
        return new self("遇到非示例內容（is_sample 不是 true）：{$where}。假資料只能放示例內容，匯出已中止，未寫入任何檔案。", self::FORBIDDEN);
    }
}
