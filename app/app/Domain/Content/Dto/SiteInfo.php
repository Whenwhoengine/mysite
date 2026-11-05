<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 品牌最小集（GET /site）。 */
final readonly class SiteInfo
{
    /**
     * @param  list<array{label: string, url: string}>  $socialLinks
     */
    public function __construct(
        public string $name,
        public string $tagline,
        public string $authorBio,
        public array $socialLinks,
        public ?Image $logo,
        public bool $isSample,
    ) {}
}
