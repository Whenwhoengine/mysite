<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\DataDeletionLog;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;
use Tests\Feature\Commerce\CommerceTestCase;

/** COM-12、DAT-06：模擬資料到期匿名化／刪除、刪除紀錄與還原後重套。 */
final class RetentionTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce();
    }

    private function seedData(): array
    {
        $variant = $this->variant('10.00', 10);
        $oldToken = $this->placeOrder($variant, 1);
        $old = Order::query()->sole();
        $old->forceFill(['placed_at' => now()->subDays(31), 'buyer_code' => 'OLD-1'])->save();
        $this->switchToNewSession();
        $newToken = $this->placeOrder($variant, 1);
        $new = Order::query()->whereKeyNot($old->id)->sole();

        app(AuditLogger::class)->record('test.old');
        AuditLog::query()->where('action', 'test.old')->update(['created_at' => now()->subDays(91)]);
        app(AuditLogger::class)->record('test.recent');

        DB::table('sessions')->insert([
            ['id' => 'stale-session', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => now()->subDays(8)->getTimestamp()],
            ['id' => 'fresh-session', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ]);

        return [$old, $oldToken, $new, $newToken];
    }

    #[Test]
    #[Group('COM-12')]
    public function retention_anonymizes_old_orders_and_purges_audit_and_sessions(): void
    {
        [$old, $oldToken, $new, $newToken] = $this->seedData();

        $this->artisan('mysite:retention:apply --dry-run')->expectsOutputToContain('[模擬測試]')->assertSuccessful();
        $this->assertNull($old->refresh()->anonymized_at, 'dry-run 不異動');

        $this->artisan('mysite:retention:apply')->expectsOutputToContain('模擬訂單匿名化 1 筆')->assertSuccessful();

        $old->refresh();
        $this->assertNotNull($old->anonymized_at);
        $this->assertNull($old->token_hash);
        $this->assertNull($old->session_binding_hash);
        $this->assertNull($old->idempotency_key);
        $this->assertNull($old->buyer_code);
        $this->assertSame('10.00', $old->total->toDecimal(), '保留統計所需的非個資欄位');
        $this->assertSame(1, $old->items()->count());
        $this->assertNull($new->refresh()->anonymized_at);

        $this->assertSame(0, AuditLog::query()->where('action', 'test.old')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'test.recent')->count());
        $this->assertFalse(DB::table('sessions')->where('id', 'stale-session')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'fresh-session')->exists());

        $this->assertTrue(DataDeletionLog::query()->where(['subject_type' => 'order', 'subject_id' => $old->id, 'action' => 'anonymize'])->exists());
        $this->assertTrue(DataDeletionLog::query()->where(['subject_type' => 'audit_logs', 'action' => 'purge'])->exists());
        $this->assertTrue(DataDeletionLog::query()->where(['subject_type' => 'sessions', 'action' => 'purge'])->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'retention.applied')->exists());

        // 第二次執行不重複處理
        $this->artisan('mysite:retention:apply')->expectsOutputToContain('模擬訂單匿名化 0 筆')->assertSuccessful();
        $this->assertSame(1, DataDeletionLog::query()->where('subject_type', 'order')->count());
    }

    #[Test]
    #[Group('COM-12')]
    #[Group('COM-09')]
    public function anonymized_order_link_no_longer_works(): void
    {
        $variant = $this->variant('10.00', 10);
        $token = $this->placeOrder($variant, 1);
        Order::query()->update(['placed_at' => now()->subDays(40)]);
        $this->artisan('mysite:retention:apply')->assertSuccessful();
        $this->get('/orders/'.$token)->assertNotFound();
    }

    #[Test]
    #[Group('DAT-06')]
    public function deletion_log_is_reapplied_after_restoring_an_old_backup(): void
    {
        [$old] = $this->seedData();
        $this->artisan('mysite:retention:apply')->assertSuccessful();
        $snapshotAnonymizedAt = $old->refresh()->anonymized_at;
        $this->assertNotNull($snapshotAnonymizedAt);

        // 模擬「還原舊備份」：被匿名化的訂單與被刪的稽核／Session 又回來了
        DB::table('orders')->where('id', $old->id)->update([
            'anonymized_at' => null, 'buyer_code' => 'OLD-1', 'token_hash' => str_repeat('e', 64),
            'session_binding_hash' => str_repeat('f', 64), 'idempotency_key' => str_repeat('a', 32),
        ]);
        DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'test.restored', 'created_at' => now()->subDays(100)]);
        DB::table('sessions')->insert(['id' => 'restored-session', 'payload' => 'x', 'last_activity' => now()->subDays(9)->getTimestamp()]);

        $this->artisan('mysite:deletion-log:reapply --dry-run')->expectsOutputToContain('[模擬測試]')->assertSuccessful();
        $this->assertNull($old->refresh()->anonymized_at);

        $this->artisan('mysite:deletion-log:reapply')->expectsOutputToContain('模擬訂單匿名化 1 筆')->assertSuccessful();
        $old->refresh();
        $this->assertNotNull($old->anonymized_at);
        $this->assertNull($old->buyer_code);
        $this->assertNull($old->token_hash);
        $this->assertSame(0, AuditLog::query()->where('action', 'test.restored')->count());
        $this->assertFalse(DB::table('sessions')->where('id', 'restored-session')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'deletion_log.reapplied')->exists());

        // 冪等：再跑一次不再處理
        $this->artisan('mysite:deletion-log:reapply')->expectsOutputToContain('模擬訂單匿名化 0 筆')->assertSuccessful();
    }

    #[Test]
    #[Group('COM-12')]
    public function retention_runs_daily_from_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(static fn ($e) => str_contains((string) $e->command, 'mysite:retention:apply'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('17 3 * * *', $event->expression);
        $this->assertSame(config('mysite.display_timezone'), $event->timezone);
    }

    #[Test]
    #[Group('COM-12')]
    public function retention_periods_have_bounded_project_defaults(): void
    {
        // 沒有永久保存的預設：三種期限都有上限（設定驗證見 MysiteConfigTest）
        $this->assertSame(30, (int) config('mysite.retention.simulation_days'));
        $this->assertSame(7, (int) config('mysite.retention.cart_days'));
        $this->assertSame(90, (int) config('mysite.retention.audit_days'));
        Carbon::setTestNow(now()->addDays(31));
        try {
            $this->assertSame(0, app(\App\Domain\Commerce\Retention\RetentionService::class)->apply(true)['orders']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
