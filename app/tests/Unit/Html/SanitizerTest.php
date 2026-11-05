<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use App\Support\Html\SafeContent;
use App\Support\Html\Sanitizer;
use Illuminate\Contracts\Support\Htmlable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** API-05：script、事件屬性、危險 URL 與不受信任 iframe 不會出現在輸出。 */
final class SanitizerTest extends TestCase
{
    private function sanitizer(): Sanitizer
    {
        return new Sanitizer('http://mysite.localhost:8080', 'http://cms.mysite.localhost:8081', 2 * 1024 * 1024);
    }

    /** @return array<string, array{string}> */
    public static function xssVectors(): array
    {
        return [
            'script' => ['<p>前</p><script>alert(1)</script><p>後</p>'],
            'script 大小寫' => ['<ScRiPt src="https://evil.example/x.js"></ScRiPt>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'img onerror https' => ['<img src="https://cdn.example.org/a.png" onerror="alert(1)" onload="alert(2)">'],
            'svg onload' => ['<svg onload=alert(1)><circle r=1></circle></svg>'],
            'javascript href' => ['<a href="javascript:alert(1)">點我</a>'],
            'javascript 混合大小寫' => ['<a href="JaVaScRiPt:alert(1)">點我</a>'],
            'javascript 實體編碼' => ['<a href="&#106;avascript:alert(1)">點我</a>'],
            'javascript tab' => ["<a href=\"java\tscript:alert(1)\">點我</a>"],
            'javascript 前置空白' => ['<a href=" javascript:alert(1)">點我</a>'],
            'data URL 連結' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">點我</a>'],
            'data URL 圖片' => ['<img src="data:image/svg+xml,&lt;svg onload=alert(1)&gt;">'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">點我</a>'],
            'iframe' => ['<iframe src="https://evil.example/"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>'],
            'object' => ['<object data="https://evil.example/x.swf"></object>'],
            'embed' => ['<embed src="https://evil.example/x.swf">'],
            'style 標籤' => ['<style>body{background:url(javascript:alert(1))}</style><p>x</p>'],
            'style 屬性' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'form' => ['<form action="https://evil.example/"><input name="q" autofocus onfocus="alert(1)"><button>送出</button></form>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            'base' => ['<base href="https://evil.example/">'],
            'div onclick' => ['<div onclick="alert(1)">文字</div>'],
            'details ontoggle' => ['<details open ontoggle="alert(1)"><summary>x</summary></details>'],
            'noscript mXSS' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>'],
            'math mXSS' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
            'template' => ['<template><img src=x onerror=alert(1)></template>'],
            'formaction' => ['<button formaction="javascript:alert(1)">x</button>'],
            'srcset' => ['<img src="https://cdn.example.org/a.png" srcset="javascript:alert(1) 1x">'],
            'blockquote cite' => ['<blockquote cite="javascript:alert(1)">引用</blockquote>'],
            'video poster' => ['<video poster="javascript:alert(1)" src="https://evil.example/a.mp4"></video>'],
            'link 樣式表' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
        ];
    }

    #[Test]
    #[Group('API-05')]
    #[DataProvider('xssVectors')]
    public function it_neutralizes_xss_vectors(string $input): void
    {
        $html = strtolower($this->sanitizer()->sanitize($input)->html());

        foreach (['<script', 'javascript:', 'vbscript:', 'data:', 'onerror', 'onload', 'onclick', 'onfocus', 'ontoggle', '<iframe', '<object', '<embed', '<svg', '<style', 'style=', '<form', '<input', '<meta', '<base', '<link', 'srcset', 'formaction', '<video', '<template', 'srcdoc'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "輸入 {$input} 的輸出仍含 {$needle}");
        }
    }

    #[Test]
    #[Group('API-05')]
    public function it_keeps_allowed_structure(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<h2>標題二</h2><h3>標題三</h3><h4>標題四</h4><p>段落<strong>粗體</strong><em>斜體</em><br>換行</p>'
            .'<figure class="wp-block-image size-large"><img src="https://cdn.example.org/a.png" alt="說明" width="800" height="450"><figcaption>圖說</figcaption></figure>'
            .'<blockquote cite="https://example.org/source"><p>引用</p><cite>出處</cite></blockquote>'
            .'<ul><li>項目</li></ul><ol start="3" reversed><li>三</li></ol><hr><pre class="wp-block-code"><code class="language-php">echo 1;</code></pre>'
            .'<table><caption>表格</caption><thead><tr><th scope="col">欄</th></tr></thead><tbody><tr><td colspan="2">值</td></tr></tbody></table>'
        )->html();

        foreach (['<h2>標題二</h2>', '<h3>標題三</h3>', '<h4>標題四</h4>', '<strong>粗體</strong>', '<em>斜體</em>', '<br>', '<figcaption>圖說</figcaption>', '<cite>出處</cite>', '<li>項目</li>', '<hr>', 'class="language-php"', '<caption>表格</caption>', 'scope="col"', 'colspan="2"', 'start="3"', 'reversed=""', 'class="wp-block-image size-large"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('<div class="rich-table"><table>', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+src="https:\/\/cdn\.example\.org\/a\.png"[^>]+alt="說明"[^>]+width="800"[^>]+height="450"[^>]+loading="lazy"/u', $html);
    }

    #[Test]
    #[Group('API-05')]
    public function external_links_get_rel_and_internal_links_do_not(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<p><a href="https://example.org/x" target="_blank">外部</a> <a href="/articles/a">站內相對</a> '
            .'<a href="http://mysite.localhost:8080/videos/b">站內絕對</a> <a href="mailto:hello@example.org">信箱</a> <a href="http://cms.mysite.localhost:8081/x">CMS</a></p>'
        )->html();

        $this->assertStringContainsString('<a href="https://example.org/x" rel="noopener noreferrer nofollow">外部</a>', $html);
        $this->assertStringContainsString('<a href="/articles/a">站內相對</a>', $html);
        $this->assertStringContainsString('<a href="http://mysite.localhost:8080/videos/b">站內絕對</a>', $html);
        $this->assertStringContainsString('<a href="mailto:hello@example.org">信箱</a>', $html);
        $this->assertStringContainsString('<a href="http://cms.mysite.localhost:8081/x" rel="noopener noreferrer nofollow">CMS</a>', $html);
        $this->assertStringNotContainsString('target=', $html);
    }

    #[Test]
    #[Group('API-05')]
    #[Group('API-13')]
    public function image_source_policy_allows_https_or_cms_and_preview_only_cms(): void
    {
        $input = '<p><img src="https://cdn.example.org/a.png" alt="第三方"><img src="http://evil.example/b.png" alt="明文第三方"><img src="http://cms.mysite.localhost:8081/wp-content/uploads/c.png" alt="CMS"><img src="/relative.png" alt="相對"></p>';
        $public = $this->sanitizer()->sanitize($input)->html();
        $this->assertStringContainsString('src="https://cdn.example.org/a.png"', $public);
        $this->assertStringContainsString('src="http://cms.mysite.localhost:8081/wp-content/uploads/c.png"', $public);
        $this->assertStringNotContainsString('evil.example', $public);
        $this->assertStringNotContainsString('relative.png', $public);
        $this->assertStringContainsString('（圖片未顯示：明文第三方）', $public);

        $preview = $this->sanitizer()->sanitize($input, true)->html();
        $this->assertStringNotContainsString('cdn.example.org', $preview, '預覽不載入第三方資源');
        $this->assertStringContainsString('src="http://cms.mysite.localhost:8081/wp-content/uploads/c.png"', $preview);
    }

    #[Test]
    #[Group('API-05')]
    public function disallowed_elements_keep_text_but_lose_markup_and_unknown_classes(): void
    {
        $html = $this->sanitizer()->sanitize('<h1 class="site-header">大標</h1><div class="wp-block-group evil"><span id="x">內文</span></div><p class="has-text-align-center site-nav js">置中</p><img src="https://cdn.example.org/a.png" width="99999999" height="abc">')->html();

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('大標', $html);
        $this->assertStringNotContainsString('<div class="wp-block-group', $html);
        $this->assertStringNotContainsString('id="x"', $html);
        $this->assertStringContainsString('<p class="has-text-align-center">置中</p>', $html);
        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringNotContainsString('height=', $html);
    }

    #[Test]
    #[Group('API-05')]
    public function safe_content_cannot_be_built_or_echoed_outside_the_sanitizer(): void
    {
        $reflection = new \ReflectionClass(SafeContent::class);
        $this->assertTrue($reflection->getConstructor()?->isPrivate());
        $this->assertFalse($reflection->implementsInterface(Htmlable::class), 'SafeContent 不可實作 Htmlable，避免 {{ }} 直接輸出');
        $this->assertFalse($reflection->hasMethod('__toString'));
        $this->assertTrue($reflection->isFinal());

        $safe = $this->sanitizer()->sanitize('<p>一 <strong>二</strong></p>');
        $this->assertSame('一 二', $safe->plainText());
        $this->assertFalse($safe->isEmpty());
        $this->assertTrue($this->sanitizer()->sanitize('<script>x</script>')->isEmpty());
    }

    #[Test]
    #[Group('API-05')]
    public function long_bodies_are_not_truncated(): void
    {
        $body = str_repeat('<p>'.str_repeat('長', 500).'</p>', 100);
        $html = $this->sanitizer()->sanitize($body)->html();

        $this->assertSame(100, substr_count($html, '<p>'));
    }
}
