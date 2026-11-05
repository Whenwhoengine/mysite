<?php

declare(strict_types=1);

namespace App\Console\Commands\Commerce;

use App\Domain\Commerce\Retention\RetentionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/** 還原備份後重套 data_deletion_log（DAT-06），避免已匿名化／已刪除資料復活。 */
final class ReapplyDeletionLog extends Command
{
    protected $signature = 'mysite:deletion-log:reapply {--dry-run : 只計算筆數，不異動資料}';

    protected $description = '還原備份後重套刪除／匿名化紀錄';

    public function handle(RetentionService $retention): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $retention->reapply($dry);
        $this->line(($dry ? '[模擬測試] 將' : '已').sprintf(
            '重套：模擬訂單匿名化 %d 筆、稽核紀錄刪除 %d 筆、Session 刪除 %d 筆。',
            $result['orders'], $result['audit_logs'], $result['sessions'],
        ));

        return SymfonyCommand::SUCCESS;
    }
}
