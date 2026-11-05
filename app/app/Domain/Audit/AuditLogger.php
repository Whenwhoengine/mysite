<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 寫入去秘密化稽核紀錄（商品、庫存、訂單、使用者、匯出、登入與 2FA、保存期限作業）。
 * 不保存 token、密碼、完整表單或 user agent；IP 只存最小化前綴。
 */
final class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        Authenticatable|int|null $actor = null,
        Model|string|null $target = null,
        int|string|null $targetId = null,
        array $metadata = [],
        string $actorType = 'user',
    ): AuditLog {
        $actorId = match (true) {
            $actor instanceof User => $actor->id,
            $actor instanceof Authenticatable => (int) $actor->getAuthIdentifier(),
            default => $actor,
        };
        if ($actorId === null && $actorType === 'user') {
            $actorType = 'anonymous';
        }
        [$targetType, $resolvedId] = match (true) {
            $target instanceof Model => [self::shortType($target), (string) $target->getKey()],
            is_string($target) => [$target, $targetId === null ? null : (string) $targetId],
            default => [null, null],
        };

        $log = new AuditLog;
        $log->forceFill([
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'action' => mb_substr($action, 0, 64),
            'target_type' => $targetType,
            'target_id' => $resolvedId,
            'metadata' => $metadata === [] ? null : MetadataRedactor::redact($metadata),
            'ip_prefix' => $actorType === 'system' ? null : IpMinimizer::minimize($this->request?->ip()),
            'created_at' => now(),
        ])->save();

        return $log;
    }

    /** 以 HMAC 取代 email 原文（例如登入失敗紀錄），可比對但不可還原。 */
    public static function hashIdentifier(string $value): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($value)), (string) config('app.key')), 0, 16);
    }

    private static function shortType(Model $model): string
    {
        return strtolower(class_basename($model));
    }
}
