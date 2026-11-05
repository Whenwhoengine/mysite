<?php

declare(strict_types=1);

namespace App\Domain\Content\Seo;

use Illuminate\Support\Str;

/**
 * 每頁唯一的 title、description、canonical 與 Open Graph（UI-06）。
 * canonical 一律為 APP_URL＋主站路徑；分頁 > 1 才帶 page。
 */
final readonly class PageMeta
{
    public function __construct(
        public string $title,
        public string $description,
        public ?string $canonical,
        public bool $noindex = false,
        public string $ogType = 'website',
        public ?string $ogImage = null,
        public ?string $ogImageAlt = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $query
     */
    public static function canonicalUrl(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if (isset($query['page']) && (int) $query['page'] <= 1) {
            unset($query['page']);
        }
        ksort($query);
        $url = rtrim((string) config('app.url'), '/').($path === '' ? '/' : $path);

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function describe(?string $text, string $fallback): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return Str::limit($text !== '' ? $text : $fallback, 150, '…');
    }

    public function fullTitle(string $siteName): string
    {
        return $this->title === '' || $this->title === $siteName ? $siteName : $this->title.'｜'.$siteName;
    }
}
