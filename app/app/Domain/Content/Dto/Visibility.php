<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

use App\Domain\Content\ContentType;

/** 可見性端點回應（不含標題或正文）。 */
final readonly class Visibility
{
    public function __construct(
        public ContentType $type,
        public int $id,
        public bool $visible,
        public int $contentVersion,
    ) {}
}
