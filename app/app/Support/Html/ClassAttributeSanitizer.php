<?php

declare(strict_types=1);

namespace App\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * class 屬性只保留 Gutenberg 排版需要的固定樣式名稱；其餘（含可能撞到本站元件的名稱）一律移除。
 */
final class ClassAttributeSanitizer implements AttributeSanitizerInterface
{
    /** @var list<string> */
    private const PATTERNS = [
        '/^wp-block-[a-z0-9-]{1,60}$/D',
        '/^wp-image-\d{1,12}$/D',
        '/^has-text-align-(left|center|right)$/D',
        '/^align(left|right|center|wide|full|none)$/D',
        '/^is-style-[a-z0-9-]{1,40}$/D',
        '/^size-(thumbnail|medium|medium_large|large|full)$/D',
        '/^is-(type|provider)-[a-z0-9-]{1,40}$/D',
        '/^has-fixed-layout$/D',
        '/^language-[a-z0-9+#-]{1,30}$/D',
    ];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return self::filter($value);
    }

    public static function filter(string $value): ?string
    {
        $kept = [];
        foreach (preg_split('/\s+/', strtolower(trim($value))) ?: [] as $token) {
            foreach (self::PATTERNS as $pattern) {
                if ($token !== '' && preg_match($pattern, $token) === 1) {
                    $kept[$token] = true;
                    break;
                }
            }
        }

        return $kept === [] ? null : implode(' ', array_keys($kept));
    }
}
