<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use Illuminate\Contracts\Session\Session;

/**
 * 商店的 Session 綁定與結帳票券。
 *
 * - binding：每個 Session 一個 32 bytes 隨機值；訂單只存 HMAC(app.key, binding)，查詢訂單時必須相符（COM-09）。
 *   Session ID 重新產生時 binding 隨 Session 資料保留。
 * - 結帳票券：GET /checkout 發給（冪等鍵, 訂單 token）一組，冪等鍵放在表單隱藏欄位；
 *   同一票券重送（含並發）一定得到同一筆訂單與同一 token。token 由 random_bytes 產生，orders 表只存 SHA-256。
 *   token 明文只留在該訪客自己的 Session（隨 Session 到期消失）。
 */
final class CommerceSession
{
    public const KEY_BINDING = 'commerce.binding';

    public const KEY_TICKETS = 'commerce.checkout_tickets';

    /** 同一 Session 最多保留的結帳票券數（暫定：5）。 */
    public const MAX_TICKETS = 5;

    public function __construct(private readonly Session $session) {}

    public function bindingHash(): string
    {
        $binding = $this->session->get(self::KEY_BINDING);
        if (! is_string($binding) || preg_match('/^[a-f0-9]{64}$/', $binding) !== 1) {
            $binding = bin2hex(random_bytes(32));
            $this->session->put(self::KEY_BINDING, $binding);
        }

        return self::hashBinding($binding);
    }

    /** 只讀：沒有 binding 時回傳 null（不建立）。 */
    public function existingBindingHash(): ?string
    {
        $binding = $this->session->get(self::KEY_BINDING);

        return is_string($binding) && preg_match('/^[a-f0-9]{64}$/', $binding) === 1 ? self::hashBinding($binding) : null;
    }

    public static function hashBinding(string $binding): string
    {
        return hash_hmac('sha256', 'commerce-binding:'.$binding, (string) config('app.key'));
    }

    /** @return array{key: string, token: string} */
    public function issueTicket(): array
    {
        $key = bin2hex(random_bytes(16));
        $token = self::base64Url(random_bytes(32));
        $tickets = $this->tickets();
        $tickets[$key] = $token;
        while (count($tickets) > self::MAX_TICKETS) {
            array_shift($tickets);
        }
        $this->session->put(self::KEY_TICKETS, $tickets);

        return ['key' => $key, 'token' => $token];
    }

    public function ticketToken(string $key): ?string
    {
        return $this->tickets()[$key] ?? null;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $key) === 1;
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, string> */
    private function tickets(): array
    {
        $tickets = $this->session->get(self::KEY_TICKETS);
        if (! is_array($tickets)) {
            return [];
        }

        return array_filter($tickets, static fn ($v, $k) => is_string($k) && is_string($v) && self::isValidKey($k) && self::isValidToken($v), ARRAY_FILTER_USE_BOTH);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
