<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * 去秘密化稽核紀錄；建立後不可修改（保存期限到期由 RetentionService 批次清理）。
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string $actor_type
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_prefix
 * @property \Carbon\CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('稽核紀錄不可修改');
        });
    }
}
