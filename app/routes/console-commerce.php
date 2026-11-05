<?php

declare(strict_types=1);

/*
 * 商店排程（由 routes/console.php 載入；scheduler 為單一實例 schedule:work）。
 *   mysite:retention:apply      — 每日：模擬訂單逾 SIMULATION_DATA_RETENTION_DAYS 匿名化、
 *                                 稽核逾 AUDIT_RETENTION_DAYS 刪除、Session（含購物車）逾 CART_RETENTION_DAYS 刪除
 *   mysite:deletion-log:reapply — 不排程；還原備份後手動執行（見 docs/runbooks/商店與後臺.md）
 * 指令類別由 App\Providers\CommerceServiceProvider 註冊。
 */

use Illuminate\Support\Facades\Schedule;

// 每日 03:17（顯示時區）執行；避開整點尖峰。
Schedule::command('mysite:retention:apply')
    ->dailyAt('03:17')
    ->timezone((string) config('mysite.display_timezone', 'Asia/Taipei'))
    ->name('commerce-retention')
    ->withoutOverlapping(60);
