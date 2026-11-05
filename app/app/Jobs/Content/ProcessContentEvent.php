<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Domain\Content\Events\ContentEventApplier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 內容事件的實際失效工作（content 佇列；交易提交後才派送）。
 */
final class ProcessContentEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('content');
        $this->afterCommit();
    }

    public function handle(ContentEventApplier $applier): void
    {
        $applier->apply($this->receiptId);
    }

    public function failed(?Throwable $e): void
    {
        app(ContentEventApplier::class)->markFailed($this->receiptId, $e !== null ? $e::class : 'unknown');
    }
}
