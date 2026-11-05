<?php

declare(strict_types=1);

namespace App\Support\Html;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * 本案唯一的 HTML allowlist sanitizer（API-05、ADR-010）。
 *
 * 第一段由 symfony/html-sanitizer 依 allowlist 清理（未列出的元素只保留文字，危險元素連內容一起移除）；
 * 第二段以 PHP DOM 補上安全屬性：外部連結 rel、圖片延後載入、圖片來源政策、數值屬性檢查、表格外框。
 */
final class Sanitizer
{
    /** 整個元素（含內容）一律移除。 */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'optgroup', 'datalist', 'output',
        'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base', 'title', 'head',
        'video', 'audio', 'source', 'track', 'canvas', 'dialog', 'portal', 'slot', 'marquee',
    ];

    private const EXTERNAL_REL = 'noopener noreferrer nofollow';

    private readonly HtmlSanitizer $sanitizer;

    private readonly ?string $appHost;

    /** @var array{scheme: string, host: string, port: int}|null */
    private readonly ?array $wpOrigin;

    public function __construct(string $appUrl, string $wpPublicUrl, int $maxInputLength)
    {
        $this->appHost = self::host($appUrl);
        $this->wpOrigin = self::origin($wpPublicUrl);

        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowElement('p', ['class'])
            ->allowElement('h2', ['class'])
            ->allowElement('h3', ['class'])
            ->allowElement('h4', ['class'])
            ->allowElement('figure', ['class'])
            ->allowElement('figcaption', ['class'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'class'])
            ->allowElement('blockquote', ['cite', 'class'])
            ->allowElement('cite')
            ->allowElement('ul', ['class'])
            ->allowElement('ol', ['class', 'start', 'reversed'])
            ->allowElement('li', ['class'])
            ->allowElement('a', ['href', 'title'])
            ->allowElement('hr')
            ->allowElement('pre', ['class'])
            ->allowElement('code', ['class'])
            ->allowElement('table', ['class'])
            ->allowElement('caption')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tfoot')
            ->allowElement('tr')
            ->allowElement('th', ['scope', 'colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('br')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(true)
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias(false)
            ->withAttributeSanitizer(new ClassAttributeSanitizer())
            ->withMaxInputLength(max(20_000, $maxInputLength));

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * @param  bool  $preview  預覽模式：圖片只允許 CMS（WP_PUBLIC_URL）來源，不載入任何第三方資源。
     */
    public function sanitize(string $html, bool $preview = false): SafeContent
    {
        $clean = $this->sanitizer->sanitize($html);

        return self::wrap(trim($clean) === '' ? '' : $this->postProcess($clean, $preview));
    }

    /** 圖片網址政策：https 任一 host，或 WP_PUBLIC_URL 的同一來源；預覽模式只允許後者。 */
    public function isAllowedMediaUrl(?string $url, bool $preview = false): bool
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }
        $origin = self::origin($url);
        if ($origin === null) {
            return false;
        }
        if ($this->wpOrigin !== null && $origin === $this->wpOrigin) {
            return true;
        }

        return ! $preview && $origin['scheme'] === 'https';
    }

    private function postProcess(string $html, bool $preview): string
    {
        $doc = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );
        $body = $doc->body;
        if ($body === null) {
            return '';
        }

        foreach (iterator_to_array($body->querySelectorAll('a[href]')) as $a) {
            /** @var Element $a */
            $href = (string) $a->getAttribute('href');
            $host = self::host($href);
            if ($host !== null && $host !== $this->appHost) {
                $a->setAttribute('rel', self::EXTERNAL_REL);
            }
        }

        foreach (iterator_to_array($body->querySelectorAll('img')) as $img) {
            /** @var Element $img */
            if (! $this->isAllowedMediaUrl($img->getAttribute('src'), $preview)) {
                $alt = trim((string) $img->getAttribute('alt'));
                $note = $doc->createElement('span');
                $note->setAttribute('class', 'rich-media-removed');
                $note->textContent = $alt !== '' ? "（圖片未顯示：{$alt}）" : '（圖片未顯示）';
                $img->replaceWith($note);

                continue;
            }
            if (! $img->hasAttribute('alt')) {
                $img->setAttribute('alt', '');
            }
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('decoding', 'async');
        }

        $numeric = ['img' => ['width' => 10000, 'height' => 10000], 'ol' => ['start' => 100000], 'th' => ['colspan' => 100, 'rowspan' => 1000], 'td' => ['colspan' => 100, 'rowspan' => 1000]];
        foreach ($numeric as $tag => $attributes) {
            foreach (iterator_to_array($body->querySelectorAll($tag)) as $el) {
                /** @var Element $el */
                foreach ($attributes as $attr => $max) {
                    $value = $el->getAttribute($attr);
                    if ($value !== null && (preg_match('/^\d{1,6}$/', $value) !== 1 || (int) $value > $max)) {
                        $el->removeAttribute($attr);
                    }
                }
            }
        }
        foreach (iterator_to_array($body->querySelectorAll('th[scope]')) as $th) {
            /** @var Element $th */
            if (! in_array($th->getAttribute('scope'), ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                $th->removeAttribute('scope');
            }
        }
        foreach (iterator_to_array($body->querySelectorAll('ol[reversed]')) as $ol) {
            /** @var Element $ol */
            $ol->setAttribute('reversed', '');
        }

        // 表格外加可水平捲動的外框，避免手機版撐破版面
        foreach (iterator_to_array($body->querySelectorAll('table')) as $table) {
            /** @var Element $table */
            $wrap = $doc->createElement('div');
            $wrap->setAttribute('class', 'rich-table');
            $table->replaceWith($wrap);
            $wrap->appendChild($table);
        }

        return $body->innerHTML;
    }

    private static function wrap(string $html): SafeContent
    {
        /** @var Closure(string): SafeContent $factory */
        $factory = Closure::bind(static fn (string $h): SafeContent => new SafeContent($h), null, SafeContent::class);

        return $factory($html);
    }

    private static function host(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** @return array{scheme: string, host: string, port: int}|null */
    private static function origin(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }
}
