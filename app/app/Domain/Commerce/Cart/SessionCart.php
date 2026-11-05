<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Cart;

use App\Domain\Commerce\CommerceMode;
use App\Domain\Commerce\Exceptions\CartLimitException;
use App\Domain\Commerce\Money;
use Illuminate\Contracts\Session\Session;

/**
 * Session 購物車（ADR-011：Session 為唯一權威來源，不建 cart_items 表）。
 *
 * 只保存 variant ID、數量與加入時的價格快照；快照只用來偵測「價格已變動」並提示，不具計價權威。
 * 存活期間：Session 本身（SESSION_LIFETIME）與 CART_RETENTION_DAYS 取較短者——
 * 讀取時若最後異動超過 retention.cart_days 即清空；retention:apply 也會清除超過該天數未活動的 sessions 列。
 */
final class SessionCart
{
    public const KEY = 'commerce.cart';

    public function __construct(
        private readonly Session $session,
        private readonly int $maxLines,
        private readonly int $maxQuantity,
        private readonly int $retentionDays,
    ) {}

    public static function for(Session $session): self
    {
        return new self(
            $session,
            CommerceMode::maxCartLines(),
            CommerceMode::maxLineQuantity(),
            (int) config('mysite.retention.cart_days', 7),
        );
    }

    /** @return array<int, CartLine> 依 variant ID 排序 */
    public function lines(): array
    {
        $raw = $this->session->get(self::KEY);
        if (! is_array($raw) || ! is_array($raw['lines'] ?? null)) {
            return [];
        }
        $touched = is_int($raw['touched_at'] ?? null) ? $raw['touched_at'] : 0;
        if ($touched < now()->subDays($this->retentionDays)->getTimestamp()) {
            $this->clear();

            return [];
        }
        $lines = [];
        foreach ($raw['lines'] as $id => $line) {
            $variantId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $qty = is_array($line) ? ($line['q'] ?? null) : null;
            $snapshot = is_array($line) ? ($line['p'] ?? null) : null;
            if ($variantId === false || ! is_int($qty) || $qty < 1 || $qty > $this->maxQuantity || ! is_string($snapshot)) {
                continue; // 損壞或被竄改的項目直接忽略
            }
            $lines[$variantId] = new CartLine($variantId, $qty, $snapshot);
            if (count($lines) >= $this->maxLines) {
                break;
            }
        }
        ksort($lines);

        return $lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (CartLine $l) => $l->quantity, $this->lines()));
    }

    /** 加入（已存在則累加）；超過上限時拋出 CartLimitException，購物車不變。 */
    public function add(int $variantId, int $quantity, Money $currentPrice): void
    {
        $this->assertQuantity($quantity);
        $lines = $this->lines();
        $existing = $lines[$variantId] ?? null;
        $newQty = ($existing?->quantity ?? 0) + $quantity;
        if ($newQty > $this->maxQuantity) {
            throw new CartLimitException("每項商品最多 {$this->maxQuantity} 件。");
        }
        if ($existing === null && count($lines) >= $this->maxLines) {
            throw new CartLimitException("購物車最多 {$this->maxLines} 個品項。");
        }
        $lines[$variantId] = new CartLine($variantId, $newQty, $existing?->priceSnapshot ?? $currentPrice->toDecimal());
        $this->write($lines);
    }

    public function update(int $variantId, int $quantity): bool
    {
        $this->assertQuantity($quantity);
        $lines = $this->lines();
        if (! isset($lines[$variantId])) {
            return false;
        }
        $lines[$variantId] = new CartLine($variantId, $quantity, $lines[$variantId]->priceSnapshot);
        $this->write($lines);

        return true;
    }

    public function remove(int $variantId): bool
    {
        $lines = $this->lines();
        if (! isset($lines[$variantId])) {
            return false;
        }
        unset($lines[$variantId]);
        $this->write($lines);

        return true;
    }

    /** 訪客已看到新價格：更新快照（不影響計價，計價永遠以 DB 為準）。 */
    public function acknowledgePrice(int $variantId, Money $currentPrice): void
    {
        $lines = $this->lines();
        if (isset($lines[$variantId]) && $lines[$variantId]->priceSnapshot !== $currentPrice->toDecimal()) {
            $lines[$variantId] = new CartLine($variantId, $lines[$variantId]->quantity, $currentPrice->toDecimal());
            $this->write($lines);
        }
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > $this->maxQuantity) {
            throw new CartLimitException("數量必須介於 1 與 {$this->maxQuantity}。");
        }
    }

    /** @param array<int, CartLine> $lines */
    private function write(array $lines): void
    {
        if ($lines === []) {
            $this->clear();

            return;
        }
        ksort($lines);
        $payload = [];
        foreach ($lines as $line) {
            $payload[$line->variantId] = ['q' => $line->quantity, 'p' => $line->priceSnapshot];
        }
        $this->session->put(self::KEY, ['v' => 1, 'touched_at' => now()->getTimestamp(), 'lines' => $payload]);
    }
}
