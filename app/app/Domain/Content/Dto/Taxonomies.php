<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 公開分類與標籤（count 只計公開內容）。 */
final readonly class Taxonomies
{
    /**
     * @param  list<Term>  $categories
     * @param  list<Term>  $tags
     */
    public function __construct(
        public array $categories,
        public array $tags,
    ) {
    }

    public function find(string $kind, string $apiSlug): ?Term
    {
        foreach ($kind === 'category' ? $this->categories : $this->tags as $term) {
            if ($term->apiSlug === $apiSlug) {
                return $term;
            }
        }

        return null;
    }
}
