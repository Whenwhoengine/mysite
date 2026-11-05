<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commerce\Exceptions\InvalidOrderTransition;
use App\Domain\Commerce\TransactionRunner;
use App\Models\InventoryMutation;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 取消模擬訂單（COM-08）：只允許 placed → cancelled；庫存回補最多一次。
 * 先鎖訂單列再依 variant ID 順序鎖庫存列；重送時看到已取消即為 no-op。
 * inventory_mutations 的 (order_id, variant, reason) 唯一約束是 DB 層的第二道防線。
 * 不刪明細、不改歷史單價。
 */
final class OrderCancellationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool true＝本次完成取消；false＝先前已取消（未重複回補） */
    public function cancel(Order $order, ?User $actor, string $source): bool
    {
        [$changed] = TransactionRunner::run(function () use ($order, $actor): bool {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new InvalidOrderTransition('訂單不存在');
            }
            if ($locked->status === OrderStatus::Cancelled) {
                return false;
            }
            if (! $locked->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new InvalidOrderTransition('此訂單狀態不可取消');
            }
            if ($locked->isAnonymized()) {
                throw new InvalidOrderTransition('已匿名化的訂單不可變更');
            }

            $items = $locked->items()->get()->sortBy('product_variant_id');
            $ids = $items->pluck('product_variant_id')->all();
            $variants = ProductVariant::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $now = now();
            foreach ($items as $item) {
                $variant = $variants->get($item->product_variant_id);
                DB::table('product_variants')->where('id', $item->product_variant_id)
                    ->increment('stock', $item->quantity, ['updated_at' => $now]);
                $mutation = new InventoryMutation;
                $mutation->forceFill([
                    'product_variant_id' => $item->product_variant_id,
                    'delta' => $item->quantity,
                    'stock_after' => (int) $variant?->stock + $item->quantity,
                    'reason' => InventoryMutation::REASON_ORDER_CANCELLED,
                    'order_id' => $locked->id,
                    'actor_user_id' => $actor?->id,
                    'created_at' => $now,
                ])->save();
            }
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => $now])->save();

            return true;
        });

        if ($changed) {
            $this->audit->record('order.cancelled', $actor, 'order', $order->id, ['source' => $source], $actor === null ? 'customer' : 'user');
        }
        $order->refresh();

        return $changed;
    }
}
