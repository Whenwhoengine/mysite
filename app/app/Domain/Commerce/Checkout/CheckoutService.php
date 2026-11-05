<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Checkout;

use App\Domain\Commerce\CommerceMode;
use App\Domain\Commerce\CommerceSession;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Money;
use App\Domain\Commerce\Notifications\SimulatedOrderNotifier;
use App\Domain\Commerce\TransactionRunner;
use App\Models\InventoryMutation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 模擬結帳（v5 §10.3；COM-03～COM-06）。
 *
 * 1. 驗證數量、行數、冪等鍵與 token 格式；同 Session 同冪等鍵已有訂單 → 直接回傳（不建第二單）。
 * 2. MySQL 交易內依 variant ID 遞增順序 lockForUpdate，從 DB 重讀上下架、價格與庫存。
 * 3. 任一行不可購買／庫存不足／價格已變 → 整筆拒絕並回復，不留半張訂單。
 * 4. 同一交易寫入訂單、明細快照、扣庫存（附 stock >= qty 條件）與庫存異動。
 * 5. 冪等鍵受 DB 唯一約束 (session_binding_hash, idempotency_key) 保護；並發重送時輸家捕捉唯一衝突後回傳贏家訂單。
 * 6. 死鎖／序列化失敗有限次重試（TransactionRunner）。
 * 7. 通知只在提交後（afterCommit）寫入郵件捕捉器。
 */
class CheckoutService
{
    public function __construct(private readonly SimulatedOrderNotifier $notifier) {}

    public function placeOrder(CheckoutCommand $command): CheckoutResult
    {
        $this->validate($command);

        $existing = $this->findExisting($command);
        if ($existing !== null) {
            return new CheckoutResult($existing, true, 0);
        }

        try {
            [$order, $attempts] = TransactionRunner::run(fn (int $attempt) => $this->transact($command));
        } catch (UniqueConstraintViolationException $e) {
            $winner = $this->findExisting($command);
            if ($winner === null) {
                throw $e;
            }

            return new CheckoutResult($winner, true, 1);
        }

        return new CheckoutResult($order, false, $attempts);
    }

    /** 測試接縫：取得庫存列鎖之後呼叫（預設不做事）。 */
    protected function afterStockLocked(Collection $variants): void {}

    /** 測試接縫：訂單、明細、庫存與異動都寫入後、提交前呼叫（預設不做事）。 */
    protected function beforeCommit(Order $order): void {}

    private function transact(CheckoutCommand $command): Order
    {
        $ids = array_keys($command->quantities);
        sort($ids, SORT_NUMERIC);

        /** @var Collection<int, ProductVariant> $variants */
        $variants = ProductVariant::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variants->load('product');

        $this->afterStockLocked($variants);

        $currency = CommerceMode::currency();
        $problems = [];
        $subtotal = Money::zero($currency);
        $lines = [];
        foreach ($ids as $id) {
            $qty = $command->quantities[$id];
            $variant = $variants->get($id);
            if ($variant === null || ! $variant->isPurchasable()) {
                $problems[$id] = 'unavailable';

                continue;
            }
            if ($variant->stock < $qty) {
                $problems[$id] = 'insufficient_stock';

                continue;
            }
            $snapshot = $command->priceSnapshots[$id] ?? null;
            if ($snapshot !== null && $snapshot !== $variant->price->toDecimal()) {
                $problems[$id] = 'price_changed';

                continue;
            }
            $lineTotal = $variant->price->multiply($qty);
            $subtotal = $subtotal->add($lineTotal);
            $lines[] = [$variant, $qty, $lineTotal];
        }
        if ($problems !== []) {
            throw new CheckoutRejected($problems);
        }

        $now = now();
        $order = new Order;
        $order->forceFill([
            'token_hash' => CommerceSession::tokenHash($command->orderToken),
            'status' => OrderStatus::Placed,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'total' => $subtotal, // 模擬商店沒有運費、稅額或折扣；總額＝伺服器計算的小計
            'item_count' => array_sum($command->quantities),
            'idempotency_key' => $command->idempotencyKey,
            'session_binding_hash' => $command->sessionBindingHash,
            'buyer_code' => $command->buyerCode,
            'placed_at' => $now,
        ])->save();

        foreach ($lines as [$variant, $qty, $lineTotal]) {
            /** @var ProductVariant $variant */
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_title' => $variant->product->title,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'unit_price' => $variant->price,
                'quantity' => $qty,
                'line_total' => $lineTotal,
            ])->save();

            $affected = DB::table('product_variants')
                ->where('id', $variant->id)
                ->where('stock', '>=', $qty)
                ->decrement('stock', $qty, ['updated_at' => $now]);
            if ($affected !== 1) {
                throw new RuntimeException('庫存扣減失敗（列鎖下不應發生）');
            }

            $mutation = new InventoryMutation;
            $mutation->forceFill([
                'product_variant_id' => $variant->id,
                'delta' => -$qty,
                'stock_after' => $variant->stock - $qty,
                'reason' => InventoryMutation::REASON_ORDER_PLACED,
                'order_id' => $order->id,
                'created_at' => $now,
            ])->save();
        }

        $this->beforeCommit($order);

        $orderId = $order->id;
        DB::afterCommit(fn () => $this->notifier->orderPlaced($orderId));

        return $order;
    }

    private function validate(CheckoutCommand $command): void
    {
        if ($command->quantities === []) {
            throw new CheckoutRejected(['cart' => 'empty_cart']);
        }
        if (count($command->quantities) > CommerceMode::maxCartLines()) {
            throw new CheckoutRejected(['cart' => 'too_many_lines']);
        }
        foreach ($command->quantities as $id => $qty) {
            if (! is_int($id) || $id < 1 || ! is_int($qty) || $qty < 1 || $qty > CommerceMode::maxLineQuantity()) {
                throw new CheckoutRejected([$id => 'invalid_quantity']);
            }
        }
        if (! CommerceSession::isValidKey($command->idempotencyKey) || ! CommerceSession::isValidToken($command->orderToken)
            || preg_match('/^[a-f0-9]{64}$/', $command->sessionBindingHash) !== 1) {
            throw new CheckoutRejected(['ticket' => 'invalid_ticket']);
        }
        if ($command->buyerCode !== null && preg_match('/^[A-Z0-9-]{1,20}$/', $command->buyerCode) !== 1) {
            throw new CheckoutRejected(['buyer_code' => 'invalid_buyer_code']);
        }
    }

    private function findExisting(CheckoutCommand $command): ?Order
    {
        return Order::query()
            ->where('session_binding_hash', $command->sessionBindingHash)
            ->where('idempotency_key', $command->idempotencyKey)
            ->first();
    }
}
