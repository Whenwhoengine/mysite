<?php

declare(strict_types=1);

namespace App\Support\Errors;

use RuntimeException;
use Throwable;

/**
 * 帶本案錯誤碼的例外；由 ErrorRenderer 轉成固定 HTTP 狀態與訪客訊息。
 */
class MysiteException extends RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode, string $internalMessage = '', ?Throwable $previous = null)
    {
        parent::__construct($internalMessage !== '' ? $internalMessage : $errorCode->value, 0, $previous);
    }
}
