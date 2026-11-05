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
        '/^wp-block-[a-z0-9-]{1,60}$/',
        '/^wp-image-\d{1,12}$/',
        '/^has-text-align-(left|center|right)$/',
        '/^align(left|right|center|wide|full|none)$/',
        '/^is-style-[a-z0-9-]{1,40}$/',
        '/^size-(thumbnail|medium|medium_large|large|full)$/',
        '/^is-(type|provider)-[a-z0-9-]{1,40}$/',
        '/^has-fixed-layout$/',
        '/^language-[a-z0-9+#-]{1,30}$/',
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
