<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

use App\Domain\Content\ContentType;
use App\Support\Html\SafeContent;
use Carbon\CarbonImmutable;

/**
 * 內容物件（契約 §2，model_version 1）。時間一律為 UTC；正文只以 SafeContent 形式存在。
 */
final readonly class ContentItem
{
    /**
     * @param  list<Term>  $categories
     * @param  list<Term>  $tags
     */
    public function __construct(
        public int $id,
        public ContentType $type,
        public string $slug,
        public string $apiSlug,
        public string $title,
        public string $excerpt,
        public ?SafeContent $body,
        public ?string $authorName,
        public array $categories,
        public array $tags,
        public ?Image $cover,
        public CarbonImmutable $publishedAt,
        public CarbonImmutable $modifiedAt,
        public int $contentVersion,
        public bool $isSample,
        public ?VideoInfo $video = null,
        public ?PodcastInfo $podcast = null,
        public bool $isPreview = false,
        public ?string $snapshotId = null,
        public ?int $revisionId = null,
    ) {}

    /** 主站相對路徑。 */
    public function path(): string
    {
        return $this->type->path($this->slug);
    }

    /** 主站絕對網址（canonical、RSS、sitemap）。 */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').$this->path();
    }
}
