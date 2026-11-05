<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Notifications;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 模擬訂單通知：只在交易提交後呼叫，且只寫入郵件捕捉器（log／array mailer）。
 * 收件者是保留網域 .invalid 的固定位址，絕不寄給訪客；mailer 不是捕捉器時直接略過（不宣稱已寄達）。
 */
class SimulatedOrderNotifier
{
    public const CAPTURE_ADDRESS = 'simulated-orders@mysite.invalid';

    public const CAPTURE_MAILERS = ['log', 'array'];

    public function orderPlaced(int $orderId): void
    {
        $mailer = (string) config('mail.default');
        if (! in_array($mailer, self::CAPTURE_MAILERS, true)) {
            Log::warning('commerce_notice_skipped', ['reason' => 'mailer_not_capture', 'order_id' => $orderId]);

            return;
        }
        $order = Order::query()->with('items')->find($orderId);
        if ($order === null) {
            return;
        }
        Mail::to(self::CAPTURE_ADDRESS)->send(new SimulatedOrderPlacedMail($order));
    }
}
