<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 封面或 logo 圖片（網址政策在顯示元件再檢查一次）。 */
final readonly class Image
{
    public function __construct(
        public string $url,
        public string $alt,
        public ?int $width,
        public ?int $height,
    ) {
    }
}
