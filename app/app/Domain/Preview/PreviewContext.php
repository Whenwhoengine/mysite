<?php

declare(strict_types=1);

namespace App\Domain\Preview;

/** 有效 preview session：claims 與（只在記憶體中解密的）票券。 */
final readonly class PreviewContext
{
    public function __construct(
        public PreviewClaims $claims,
        #[\SensitiveParameter] public string $ticket,
    ) {
    }

    /** 避免 dump／log 時帶出票券。 */
    public function __debugInfo(): array
    {
        return ['claims' => $this->claims, 'ticket' => '***'];
    }
}
