<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * 假資料轉換層（定案執行書 §5 M-19；v1 §4.4、§7 F2 第 5 步）。
 *
 * 把公開 API 的真實回應轉成前端假資料模式用的 JSON。轉換只在這裡做，正式來源政策
 * （MediaPolicy、Sanitizer）一律不動、不放寬：
 * 1. 只收示例內容：每一筆內容與網站資訊的 is_sample 必須是 true，否則中止（v1 §4.4）。
 * 2. 媒體網址改寫：圖片 url、seo.og_image、podcast.audio_url、content_html 內 <img src> 一律改成
 *    /mock-media/<檔名>（相對路徑），指向 MockMedia 產生的合成檔；YouTube 的 watch_url／embed_url 保留。
 * 3. 植入哨兵字串：每筆內容的 excerpt 與 content_html 開頭、網站 tagline、每個回應的 seo.description 開頭
 *    加上 SENTINEL（M-19、FE-09 會在前端建置產物與映像全文搜尋它）。content_html 只以 DOM 新增一個
 *    純文字 <p> 段落並替換既有 <img> 的 src 值，不新增其他元素或屬性。
 * 4. 轉換後以 assertClean() 逐欄自我檢查，再由呼叫端以契約驗證（MockContract）。
 */
final class MockTransformer
{
    /** 假資料哨兵字串：固定值，不得更改（前端 CI 與映像檢查以它判斷假資料是否外洩）。 */
    public const SENTINEL = 'MSMOCK-7F3A9C1E';

    public const KINDS = ['site', 'home', 'list', 'detail', 'taxonomy', 'search'];

    /**
     * @param  'site'|'home'|'list'|'detail'|'taxonomy'|'search'  $kind  回應種類（對應契約的 *Response）
     * @param  array<string, mixed>  $payload  API 回應（已 json_decode 成關聯陣列）
     * @param  string  $source  來源描述（錯誤訊息用，例如 API 路徑）
     * @return array<string, mixed>
     *
     * @throws MockExportException
     */
    public function transform(string $kind, array $payload, string $source): array
    {
        $out = match ($kind) {
            'site' => $this->site($payload, $source),
            'home' => $this->home($payload, $source),
            'list' => $this->list($payload, $source),
            'detail' => $this->detailResponse($payload, $source),
            'taxonomy' => $this->taxonomy($payload, $source),
            'search' => $this->search($payload, $source),
            default => throw new MockExportException("未知的回應種類：{$kind}"),
        };
        $this->assertClean($out, $source);
        $this->assertNoCmsUrls($out, $source);

        return $out;
    }

    /**
     * 全文檢查：輸出裡不得殘留任何 CMS 網址（WP_PUBLIC_URL 的來源或 /wp-content/uploads/），
     * 不論出現在哪個欄位（例如正文裡「連結到媒體檔」產生的 <a href>）。前端 CI 不得向外部發請求。
     *
     * @param  array<mixed>  $payload
     *
     * @throws MockExportException
     */
    public function assertNoCmsUrls(array $payload, string $source): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $needles = ['/wp-content/uploads/'];
        $host = self::cmsHost();
        if ($host !== null) {
            $needles[] = $host; // 以主機名比對：涵蓋 http(s)://、//主機/（相對協定）與主機名尾端多一個點
        }
        foreach ($needles as $needle) {
            if (stripos($json, $needle) !== false) {
                throw new MockExportException("{$source} 殘留 CMS 網址（{$needle}）");
            }
        }
    }

    /** WP_PUBLIC_URL 的主機名（小寫）；未設定時為 null。 */
    private static function cmsHost(): ?string
    {
        $host = parse_url((string) config('mysite.wp_public_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
    }

    /** 指向 CMS 的網址：含 /wp-content/uploads/，或主機是 CMS（不分協定、含 //主機/ 寫法與尾端的點）。 */
    private static function isCmsUrl(string $url): bool
    {
        if (stripos($url, '/wp-content/uploads/') !== false) {
            return true;
        }
        $cms = self::cmsHost();
        $host = parse_url(trim($url), PHP_URL_HOST);

        return $cms !== null && is_string($host) && strtolower(rtrim($host, '.')) === $cms;
    }

    /**
     * 轉換後的自我檢查（逐欄，失敗即中止）：
     * is_sample 一律為 true；圖片、og_image、audio_url、content_html 的 <img src> 只指向合成檔；
     * excerpt、content_html 以哨兵開頭。
     *
     * @param  array<mixed>  $node
     *
     * @throws MockExportException
     */
    public function assertClean(array $node, string $source, string $at = '$'): void
    {
        $allowed = MockMedia::urls();
        if (array_key_exists('is_sample', $node) && $node['is_sample'] !== true) {
            throw MockExportException::nonSample("{$source} {$at}");
        }
        $keys = array_keys($node);
        sort($keys);
        if ($keys === ['alt', 'height', 'url', 'width'] && ! in_array($node['url'], $allowed, true)) {
            throw new MockExportException("{$source} {$at}.url 未改寫成合成媒體");
        }
        foreach (['og_image', 'audio_url'] as $field) {
            if (array_key_exists($field, $node) && $node[$field] !== null && ! in_array($node[$field], $allowed, true)) {
                throw new MockExportException("{$source} {$at}.{$field} 未改寫成合成媒體");
            }
        }
        if (array_key_exists('excerpt', $node) && ! str_starts_with((string) $node['excerpt'], self::SENTINEL)) {
            throw new MockExportException("{$source} {$at}.excerpt 缺少哨兵字串");
        }
        if (array_key_exists('content_html', $node)) {
            $html = (string) $node['content_html'];
            if (! str_starts_with($html, '<p>'.self::SENTINEL.'</p>')) {
                throw new MockExportException("{$source} {$at}.content_html 缺少哨兵段落");
            }
            foreach (self::imageSources($html) as $src) {
                if (! in_array($src, $allowed, true)) {
                    throw new MockExportException("{$source} {$at}.content_html 含未改寫的圖片來源");
                }
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->assertClean($value, $source, $at.(is_int($key) ? "[{$key}]" : ".{$key}"));
            }
        }
    }

    /** @return list<string> content_html 內所有 <img> 的 src */
    public static function imageSources(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $sources = [];
        foreach (self::parse($html)->body?->querySelectorAll('img') ?? [] as $img) {
            /** @var Element $img */
            $sources[] = (string) $img->getAttribute('src');
        }

        return $sources;
    }

    /** 在純文字欄位開頭加哨兵（空字串時只放哨兵）。 */
    public static function prefix(string $text): string
    {
        return $text === '' ? self::SENTINEL : self::SENTINEL.' '.$text;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function site(array $payload, string $source): array
    {
        $site = self::object($payload, 'data', $source);
        if (($site['is_sample'] ?? null) !== true) {
            throw MockExportException::nonSample("{$source} data（網站資訊；fallback=".json_encode($site['fallback'] ?? null).'）');
        }
        $site['tagline'] = self::prefix((string) ($site['tagline'] ?? ''));
        $site['logo'] = $this->image($site['logo'] ?? null, MockMedia::LOGO);
        $payload['data'] = $site;

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function home(array $payload, string $source): array
    {
        $data = self::object($payload, 'data', $source);
        if ($data['featured'] !== null) {
            $data['featured'] = $this->summary(self::assoc($data['featured'], "{$source} data.featured"), "{$source} data.featured");
        }
        foreach (['posts', 'videos', 'podcasts'] as $section) {
            $data[$section] = $this->summaries($data[$section] ?? null, "{$source} data.{$section}");
        }
        $payload['data'] = $data;
        $payload['seo'] = $this->seo($payload['seo'] ?? null, $source);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function list(array $payload, string $source): array
    {
        $payload['data'] = $this->summaries($payload['data'] ?? null, "{$source} data");
        $payload['seo'] = $this->seo($payload['seo'] ?? null, $source);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function detailResponse(array $payload, string $source): array
    {
        $payload['data'] = $this->detail(self::object($payload, 'data', $source), "{$source} data");
        $payload['seo'] = $this->seo($payload['seo'] ?? null, $source);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function taxonomy(array $payload, string $source): array
    {
        $data = self::object($payload, 'data', $source);
        if (is_array($data['overview'] ?? null)) {
            foreach ($data['overview'] as $type => $section) {
                $section = self::assoc($section, "{$source} data.overview.{$type}");
                $section['items'] = $this->summaries($section['items'] ?? null, "{$source} data.overview.{$type}.items");
                $data['overview'][$type] = $section;
            }
        }
        if (($data['items'] ?? null) !== null) {
            $data['items'] = $this->summaries($data['items'], "{$source} data.items");
        }
        $payload['data'] = $data;
        $payload['seo'] = $this->seo($payload['seo'] ?? null, $source);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function search(array $payload, string $source): array
    {
        $data = self::object($payload, 'data', $source);
        $data['items'] = $this->summaries($data['items'] ?? null, "{$source} data.items");
        $payload['data'] = $data;
        $payload['seo'] = $this->seo($payload['seo'] ?? null, $source);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function summaries(mixed $items, string $where): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new MockExportException("{$where} 不是陣列");
        }
        $out = [];
        foreach ($items as $i => $item) {
            $out[] = $this->summary(self::assoc($item, "{$where}[{$i}]"), "{$where}[{$i}]");
        }

        return $out;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function summary(array $item, string $where): array
    {
        if (($item['is_sample'] ?? null) !== true) {
            throw MockExportException::nonSample("{$where}（slug：".(string) ($item['slug'] ?? '?').'）');
        }
        $item['excerpt'] = self::prefix((string) ($item['excerpt'] ?? ''));
        $item['cover'] = $this->image($item['cover'] ?? null, MockMedia::COVER);

        return $item;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function detail(array $item, string $where): array
    {
        $item = $this->summary($item, $where);
        $item['content_html'] = $this->html((string) ($item['content_html'] ?? ''));
        if (is_array($item['podcast'] ?? null) && ($item['podcast']['audio_url'] ?? null) !== null) {
            // 合成音檔是 WAV，MIME 跟著改，避免前端以原本的 MIME（例如 audio/mpeg）解碼
            $item['podcast']['audio_url'] = MockMedia::url(MockMedia::AUDIO);
            $item['podcast']['audio_mime'] = 'audio/wav';
        }

        // video.watch_url／embed_url 保留：前端點擊前不連線，E2E 會攔下
        return $item;
    }

    /** @return array<string, mixed> */
    private function seo(mixed $seo, string $source): array
    {
        $seo = self::assoc($seo, "{$source} seo");
        $seo['description'] = self::prefix((string) ($seo['description'] ?? ''));
        if (($seo['og_image'] ?? null) !== null) {
            $seo['og_image'] = MockMedia::url(MockMedia::COVER);
        }

        return $seo;
    }

    /** @return array<string, mixed>|null */
    private function image(mixed $image, string $file): ?array
    {
        if ($image === null) {
            return null;
        }
        $image = self::assoc($image, '圖片');
        $image['url'] = MockMedia::url($file);

        return $image;
    }

    /**
     * 富文字：替換既有 <img> 的 src 值、指向 CMS 的 <a href> 與 <blockquote cite>，並在最前面加一個純文字 <p> 哨兵段落。
     * 不新增其他元素或屬性；輸入本來就是 Sanitizer 以同一個 DOM 序列化器產生的輸出。
     */
    private function html(string $html): string
    {
        $doc = self::parse($html);
        $body = $doc->body;
        if ($body === null) {
            throw new MockExportException('content_html 無法解析');
        }
        foreach (iterator_to_array($body->querySelectorAll('img')) as $img) {
            /** @var Element $img */
            $img->setAttribute('src', MockMedia::url(MockMedia::INLINE));
        }
        // 正文裡指向 CMS 的連結（例如圖片設成「連結到媒體檔」）與引用來源，同樣改指合成檔
        foreach ([['a', 'href'], ['blockquote', 'cite']] as [$tag, $attr]) {
            foreach (iterator_to_array($body->querySelectorAll($tag.'['.$attr.']')) as $el) {
                /** @var Element $el */
                if (self::isCmsUrl((string) $el->getAttribute($attr))) {
                    $el->setAttribute($attr, MockMedia::url(MockMedia::INLINE));
                }
            }
        }
        $sentinel = $doc->createElement('p');
        $sentinel->textContent = self::SENTINEL;
        $body->prepend($sentinel);

        return $body->innerHTML;
    }

    private static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function object(array $payload, string $key, string $source): array
    {
        return self::assoc($payload[$key] ?? null, "{$source} {$key}");
    }

    /** @return array<string, mixed> */
    private static function assoc(mixed $value, string $where): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new MockExportException("{$where} 不是物件");
        }

        return $value;
    }
}
