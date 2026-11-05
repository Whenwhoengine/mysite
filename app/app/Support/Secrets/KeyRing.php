<?php

declare(strict_types=1);

namespace App\Support\Secrets;

use RuntimeException;

/**
 * 簽章金鑰圈（GAP-KEYRING-01、規劃書 v5 附錄 D.3「新舊 key ID 過渡，不能無期限接受舊 key」）。
 *
 * 本類別在框架啟動前就會被 bootstrap/secrets.php 直接 require，因此不得依賴 Composer 或 Laravel。
 *
 * 金鑰圈檔格式（與 scripts/ops/rotate-secrets.sh 相同）：每個非空行 `<key_id> <64 hex> <active|accept>`，
 * key_id 符合 `k[0-9]{1,6}`、不得重複，且恰好一把 active。
 * - active：發送端以它簽章；接收端也接受。
 * - accept：只供接收端驗證（輪替 add 階段的新 key，或 promote 後到 retire 前的舊 key）。
 * retire 會把舊 key 移出金鑰圈，之後以它簽的事件與票券一律被拒（不無期限接受舊 key）。
 *
 * 沒有金鑰圈時以 single() 沿用單一金鑰（視為唯一一把 active），保持向後相容。
 * 錯誤訊息只含行號與欄位種類，絕不含金鑰內容。
 */
final class KeyRing
{
    public const KEY_ID_PATTERN = '/^k[0-9]{1,6}$/D';

    public const KEY_PATTERN = '/^[0-9a-f]{64}$/D';

    public const ACTIVE = 'active';

    public const ACCEPT = 'accept';

    /**
     * @param  array<string, array{key: string, state: string}>  $keys  key_id → 金鑰與狀態
     */
    private function __construct(
        private readonly array $keys,
        private readonly string $activeId,
    ) {}

    /**
     * 解析金鑰圈檔內容；任何格式錯誤都拋出例外（呼叫端據此拒絕啟動）。
     *
     * @throws RuntimeException
     */
    public static function parse(string $label, #[\SensitiveParameter] string $content): self
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = preg_split('/[ \t]+/', trim($line)) ?: [];
            $rows[] = [$index + 1, $parts];
        }

        return self::build($label, $rows);
    }

    /**
     * 單一金鑰相容模式：沒有金鑰圈時，把單一金鑰視為唯一一把 active。
     * key ID 沿用既有設定（事件為 CONTENT_EVENT_KEY_ID，預覽固定 k1）；金鑰可為空字串（表示未設定）。
     */
    public static function single(string $keyId, #[\SensitiveParameter] string $key): self
    {
        return new self([$keyId => ['key' => $key, 'state' => self::ACTIVE]], $keyId);
    }

    /**
     * 由單行精簡格式還原（`k1:<hex>:active,k2:<hex>:accept`）；供環境變數與設定快取使用，同樣嚴格驗證。
     *
     * @throws RuntimeException
     */
    public static function fromCompact(string $label, #[\SensitiveParameter] string $compact): self
    {
        $rows = [];
        foreach (explode(',', $compact) as $index => $entry) {
            $rows[] = [$index + 1, explode(':', $entry)];
        }

        return self::build($label, $rows);
    }

    /** 匯出為單行精簡格式（不含換行；只供環境變數與設定使用，不得輸出到 log）。 */
    public function toCompact(): string
    {
        $out = [];
        foreach ($this->keys as $id => $entry) {
            $out[] = $id.':'.$entry['key'].':'.$entry['state'];
        }

        return implode(',', $out);
    }

    public function activeId(): string
    {
        return $this->activeId;
    }

    public function activeKey(): string
    {
        return $this->keys[$this->activeId]['key'];
    }

    /** 依 key ID 取得可用於驗證的金鑰（active 或 accept）；不在金鑰圈內回傳 null。 */
    public function find(string $keyId): ?string
    {
        $found = null;
        // 逐一以常數時間比較，不因 key ID 命中與否改變比較次數
        foreach ($this->keys as $id => $entry) {
            if (hash_equals((string) $id, $keyId)) {
                $found = $entry['key'];
            }
        }

        return $found;
    }

    /**
     * key ID → 狀態（依 key ID 數字遞增排序；不含金鑰，可供狀態輸出與輪替驗證）。
     *
     * @return array<string, string>
     */
    public function states(): array
    {
        $states = [];
        foreach ($this->keys as $id => $entry) {
            $states[(string) $id] = $entry['state'];
        }
        uksort($states, static fn (string $a, string $b): int => self::order($a) <=> self::order($b) ?: strcmp($a, $b));

        return $states;
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * @param  list<array{0: int, 1: list<string>}>  $rows  [行號, 欄位]
     *
     * @throws RuntimeException
     */
    private static function build(string $label, array $rows): self
    {
        $keys = [];
        $activeId = null;
        foreach ($rows as [$line, $parts]) {
            if (count($parts) !== 3) {
                throw new RuntimeException("{$label} 第 {$line} 筆格式不合法（必須是 <key_id> <64 hex> <active|accept>）");
            }
            [$id, $key, $state] = $parts;
            if (preg_match(self::KEY_ID_PATTERN, $id) !== 1) {
                throw new RuntimeException("{$label} 第 {$line} 筆的 key ID 不合法（必須符合 k[0-9]{1,6}）");
            }
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new RuntimeException("{$label} 第 {$line} 筆的金鑰不是 64 字元小寫 hex");
            }
            if ($state !== self::ACTIVE && $state !== self::ACCEPT) {
                throw new RuntimeException("{$label} 第 {$line} 筆的狀態必須是 active 或 accept");
            }
            if (isset($keys[$id])) {
                throw new RuntimeException("{$label} 的 key ID {$id} 重複");
            }
            if ($state === self::ACTIVE) {
                if ($activeId !== null) {
                    throw new RuntimeException("{$label} 必須恰好一把 active（發現多把）");
                }
                $activeId = $id;
            }
            $keys[$id] = ['key' => $key, 'state' => $state];
        }
        if ($keys === []) {
            throw new RuntimeException("{$label} 內容為空");
        }
        if ($activeId === null) {
            throw new RuntimeException("{$label} 必須恰好一把 active（沒有 active）");
        }

        return new self($keys, $activeId);
    }

    private static function order(string $id): int
    {
        return preg_match('/^k([0-9]{1,6})$/D', $id, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;
    }
}
