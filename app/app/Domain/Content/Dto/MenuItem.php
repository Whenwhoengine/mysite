<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 選單項目：內部連結為主站相對路徑，外部連結只允許 https。 */
final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $url,
        public bool $external,
    ) {}
}
