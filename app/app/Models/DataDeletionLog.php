<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 刪除／匿名化紀錄（DAT-06），供還原備份後重套。
 *
 * @property int $id
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $action
 * @property array<string, mixed>|null $criteria
 * @property string $reason
 * @property int $affected_rows
 * @property \Carbon\CarbonImmutable $executed_at
 */
class DataDeletionLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'data_deletion_log';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'affected_rows' => 'integer',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
