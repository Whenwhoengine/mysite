<?php

declare(strict_types=1);

namespace App\Support\Html;

/**
 * 已經過唯一 allowlist sanitizer 處理的 HTML（ADR-010）。
 *
 * - 建構子為 private：只有 {@see Sanitizer} 能產生實例，其他程式無法把任意字串包裝成「安全 HTML」。
 * - 刻意「不」實作 Htmlable 與 __toString：避免在 Blade `{{ }}` 中被當成已跳脫內容直接輸出。
 *   唯一輸出點是 resources/views/components/rich-content.blade.php。
 */
final class SafeContent
{
    private function __construct(private readonly string $html) {}

    /** 只供 rich-content 元件輸出使用。 */
    public function html(): string
    {
        return $this->html;
    }

    public function isEmpty(): bool
    {
        return trim(strip_tags($this->html)) === '' && ! str_contains($this->html, '<img');
    }

    /** 純文字版本（摘要、字數估計用；輸出時仍須經 Blade 跳脫）。 */
    public function plainText(): string
    {
        $text = html_entity_decode(strip_tags($this->html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
