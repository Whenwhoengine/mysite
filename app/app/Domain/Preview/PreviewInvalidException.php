<?php

declare(strict_types=1);

namespace App\Domain\Preview;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;

/**
 * 預覽失效（403 PREVIEW_INVALID）。reason 只進受限 log；訊息與 log 都不含票券內容。
 */
final class PreviewInvalidException extends MysiteException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(ErrorCode::PreviewInvalid, 'preview_invalid:'.$reason);
    }
}
