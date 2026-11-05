<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Retention;

use App\Domain\Audit\AuditLogger;
use App\Models\DataDeletionLog;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 資料保存期限作業（COM-12、DAT-06）。保存天數為專案預設（非法定期限），由設定目錄的
 * SIMULATION_DATA_RETENTION_DAYS／AUDIT_RETENTION_DAYS／CART_RETENTION_DAYS 控制。
 *
 * - 模擬訂單：placed_at 超過 simulation_days → 匿名化（清除 token_hash、Session 綁定、冪等鍵、測試代號），
 *   保留金額、件數、狀態與明細的非個資統計欄位；每筆寫入 data_deletion_log。
 * - 稽核紀錄：created_at 超過 audit_days → 刪除，寫一筆條件式刪除紀錄。
 * - 購物車：只存在 Session；sessions 表 last_activity 超過 cart_days → 刪除，寫一筆條件式刪除紀錄。
 */
final class RetentionService
{
    public const ACTION_ANONYMIZE = 'anonymize';

    public const ACTION_PURGE = 'purge';

    /** 每批處理筆數（一般迴圈分批大小，非政策門檻）。 */
    private const BATCH = 500;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{orders: int, audit_logs: int, sessions: int, dry_run: bool} */
    public function apply(bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $orderCutoff = $now->subDays((int) config('mysite.retention.simulation_days'));
        $auditCutoff = $now->subDays((int) config('mysite.retention.audit_days'));
        $sessionCutoff = $now->subDays((int) config('mysite.retention.cart_days'));

        $ordersQuery = Order::query()->whereNull('anonymized_at')->where('placed_at', '<', $orderCutoff);
        $auditQuery = DB::table('audit_logs')->where('created_at', '<', $auditCutoff);
        $sessionQuery = DB::table('sessions')->where('last_activity', '<', $sessionCutoff->getTimestamp());

        if ($dryRun) {
            return [
                'orders' => $ordersQuery->count(),
                'audit_logs' => $auditQuery->count(),
                'sessions' => $sessionQuery->count(),
                'dry_run' => true,
            ];
        }

        $orders = 0;
        $ordersQuery->select('id')->chunkById(self::BATCH, function ($chunk) use (&$orders, $now): void {
            foreach ($chunk as $order) {
                $orders += $this->anonymizeOrder((int) $order->id, $now, 'retention');
            }
        });

        // 條件式刪除只在確實刪到資料時記錄；截止時間單調遞增，最新一筆即涵蓋較舊的條件。
        $auditDeleted = $auditQuery->delete();
        if ($auditDeleted > 0) {
            $this->log('audit_logs', null, self::ACTION_PURGE, ['created_before' => $auditCutoff->toIso8601String()], 'retention', $auditDeleted, $now);
        }

        $sessionsDeleted = $sessionQuery->delete();
        if ($sessionsDeleted > 0) {
            $this->log('sessions', null, self::ACTION_PURGE, ['last_activity_before' => $sessionCutoff->getTimestamp()], 'retention', $sessionsDeleted, $now);
        }

        $this->audit->record('retention.applied', null, null, null, [
            'orders_anonymized' => $orders, 'audit_logs_deleted' => $auditDeleted, 'sessions_deleted' => $sessionsDeleted,
        ], 'system');

        return ['orders' => $orders, 'audit_logs' => $auditDeleted, 'sessions' => $sessionsDeleted, 'dry_run' => false];
    }

    /**
     * 還原備份後重套刪除紀錄（DAT-06）：已匿名化／已刪除者不重複處理。
     *
     * @return array{orders: int, audit_logs: int, sessions: int, dry_run: bool}
     */
    public function reapply(bool $dryRun = false): array
    {
        $result = ['orders' => 0, 'audit_logs' => 0, 'sessions' => 0, 'dry_run' => $dryRun];
        $now = CarbonImmutable::now();

        DataDeletionLog::query()->orderBy('id')->chunkById(self::BATCH, function ($entries) use (&$result, $dryRun, $now): void {
            foreach ($entries as $entry) {
                /** @var DataDeletionLog $entry */
                if ($entry->subject_type === 'order' && $entry->action === self::ACTION_ANONYMIZE && $entry->subject_id !== null) {
                    $pending = Order::query()->whereKey($entry->subject_id)->whereNull('anonymized_at')->exists();
                    if ($pending) {
                        $result['orders'] += $dryRun ? 1 : $this->anonymizeOrder($entry->subject_id, $now, 'reapply', false);
                    }
                } elseif ($entry->subject_type === 'audit_logs' && $entry->action === self::ACTION_PURGE) {
                    $before = (string) ($entry->criteria['created_before'] ?? '');
                    if ($before !== '') {
                        $q = DB::table('audit_logs')->where('created_at', '<', CarbonImmutable::parse($before));
                        $result['audit_logs'] += $dryRun ? $q->count() : $q->delete();
                    }
                } elseif ($entry->subject_type === 'sessions' && $entry->action === self::ACTION_PURGE) {
                    $before = $entry->criteria['last_activity_before'] ?? null;
                    if (is_int($before)) {
                        $q = DB::table('sessions')->where('last_activity', '<', $before);
                        $result['sessions'] += $dryRun ? $q->count() : $q->delete();
                    }
                }
            }
        });

        if (! $dryRun) {
            $this->audit->record('deletion_log.reapplied', null, null, null, [
                'orders_anonymized' => $result['orders'], 'audit_logs_deleted' => $result['audit_logs'], 'sessions_deleted' => $result['sessions'],
            ], 'system');
        }

        return $result;
    }

    private function anonymizeOrder(int $orderId, CarbonImmutable $now, string $reason, bool $writeLog = true): int
    {
        return DB::transaction(function () use ($orderId, $now, $reason, $writeLog): int {
            $affected = DB::table('orders')->where('id', $orderId)->whereNull('anonymized_at')->update([
                'token_hash' => null,
                'session_binding_hash' => null,
                'idempotency_key' => null,
                'buyer_code' => null,
                'anonymized_at' => $now,
                'updated_at' => $now,
            ]);
            if ($affected === 1 && $writeLog) {
                $this->log('order', $orderId, self::ACTION_ANONYMIZE, null, $reason, 1, $now);
            }

            return $affected;
        });
    }

    /** @param array<string, mixed>|null $criteria */
    private function log(string $type, ?int $id, string $action, ?array $criteria, string $reason, int $affected, CarbonImmutable $now): void
    {
        $entry = new DataDeletionLog;
        $entry->forceFill([
            'subject_type' => $type,
            'subject_id' => $id,
            'action' => $action,
            'criteria' => $criteria,
            'reason' => $reason,
            'affected_rows' => $affected,
            'executed_at' => $now,
            'created_at' => $now,
        ])->save();
    }
}
