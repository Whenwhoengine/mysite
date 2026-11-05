<?php

declare(strict_types=1);

namespace App\Models;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::Active => '上架中',
            self::Archived => '已封存',
        };
    }
}
