<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 一頁列表結果（分頁欄位來自 WP meta）。 */
final readonly class ContentPage
{
    /**
     * @param  list<ContentItem>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public int $totalPages,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public static function empty(int $perPage = 12): self
    {
        return new self([], 1, $perPage, 0, 0);
    }
}
