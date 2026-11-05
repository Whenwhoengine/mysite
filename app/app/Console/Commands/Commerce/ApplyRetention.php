<?php

declare(strict_types=1);

namespace App\Console\Commands\Commerce;

use App\Domain\Commerce\Retention\RetentionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/** 套用保存期限（模擬訂單匿名化、稽核清理、過期 Session／購物車清理）。每日由排程執行。 */
final class ApplyRetention extends Command
{
    protected $signature = 'mysite:retention:apply {--dry-run : 只計算筆數，不異動資料}';

    protected $description = '依 SIMULATION_DATA_RETENTION_DAYS／AUDIT_RETENTION_DAYS／CART_RETENTION_DAYS 匿名化或清理資料';

    public function handle(RetentionService $retention): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $retention->apply($dry);
        $this->line(($dry ? '[模擬測試] 將' : '已').sprintf(
            '處理：模擬訂單匿名化 %d 筆、稽核紀錄刪除 %d 筆、過期 Session（含購物車）刪除 %d 筆。',
            $result['orders'], $result['audit_logs'], $result['sessions'],
        ));

        return SymfonyCommand::SUCCESS;
    }
}
