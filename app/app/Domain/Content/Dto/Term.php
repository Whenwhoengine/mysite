<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 分類或標籤。 */
final readonly class Term
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $apiSlug,
        public string $name,
        public ?int $count = null,
    ) {}
}
