<?php

declare(strict_types=1);

namespace App\Domain\Preview;

use App\Domain\Content\ContentType;

/** 已驗證的預覽票券 claims（契約 §5）。 */
final readonly class PreviewClaims
{
    public function __construct(
        public string $iss,
        public string $aud,
        public ContentType $ctype,
        public int $cid,
        public string $sid,
        public int $rev,
        public int $iat,
        public int $exp,
        public string $jti,
        public int $sub,
    ) {}
}
