<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commerce\Exceptions\InventoryException;
use App\Domain\Commerce\TransactionRunner;
use App\Models\InventoryMutation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 後臺庫存調整：交易內鎖列、調整後不得為負、只追加異動紀錄並寫稽核。
 */
final class InventoryService
{
    /** unsigned INT 上限（DB 欄位限制，非政策門檻）。 */
    public const MAX_STOCK = 4_294_967_295;

    public function __construct(private readonly AuditLogger $audit) {}

    public function adjust(ProductVariant $variant, int $delta, ?User $actor, ?string $note = null, string $reason = InventoryMutation::REASON_MANUAL): InventoryMutation
    {
        if ($delta === 0) {
            throw new InventoryException('調整數量不可為 0。');
        }
        [$mutation] = TransactionRunner::run(function () use ($variant, $delta, $actor, $note, $reason): InventoryMutation {
            /** @var ProductVariant $locked */
            $locked = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $after = $locked->stock + $delta;
            if ($after < 0) {
                throw new InventoryException("調整後庫存不得為負數（目前 {$locked->stock}）。");
            }
            if ($after > self::MAX_STOCK) {
                throw new InventoryException('調整後庫存超過上限。');
            }
            $now = now();
            DB::table('product_variants')->where('id', $locked->id)->update(['stock' => $after, 'updated_at' => $now]);
            $mutation = new InventoryMutation;
            $mutation->forceFill([
                'product_variant_id' => $locked->id,
                'delta' => $delta,
                'stock_after' => $after,
                'reason' => $reason,
                'actor_user_id' => $actor?->id,
                'note' => $note === null ? null : mb_substr($note, 0, 200),
                'created_at' => $now,
            ])->save();

            return $mutation;
        });

        $this->audit->record(
            $reason === InventoryMutation::REASON_INITIAL ? 'inventory.initialized' : 'inventory.adjusted',
            $actor,
            'product_variant',
            $variant->id,
            ['delta' => $delta, 'stock_after' => $mutation->stock_after, 'note' => $note],
            $actor === null ? 'system' : 'user',
        );
        $variant->refresh();

        return $mutation;
    }
}
