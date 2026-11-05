<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Domain\Content\ContentQueryService;
use App\Domain\Content\Support\DisplayTime;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use XMLWriter;

/**
 * RSS 2.0 與 sitemap（UI-06、API-13、API-15）：只含主站公開網址；資料來自公開列表（快取受內容世代號與
 * 可見性期限約束），預覽與非公開內容不會出現。XML 以 XMLWriter 產生（自動跳脫）。
 */
final class FeedController extends Controller
{
    public function __construct(private readonly ContentQueryService $content) {}

    public function feed(): Response
    {
        $items = $this->content->feedItems();
        $site = $this->content->site();
        $siteName = $site?->name ?? (string) config('mysite.site_name');
        $base = rtrim((string) config('app.url'), '/');

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $xml->startElement('channel');
        $xml->writeElement('title', $siteName);
        $xml->writeElement('link', $base.'/');
        $xml->writeElement('description', $site?->tagline !== null && $site->tagline !== '' ? $site->tagline : $siteName.'：影片、Podcast 與文章。');
        $xml->writeElement('language', 'zh-TW');
        $xml->startElement('atom:link');
        $xml->writeAttribute('href', $base.'/feed.xml');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('type', 'application/rss+xml');
        $xml->endElement();
        if ($items !== []) {
            $xml->writeElement('lastBuildDate', $items[0]->publishedAt->setTimezone('UTC')->toRfc2822String());
        }
        foreach ($items as $item) {
            $xml->startElement('item');
            $xml->writeElement('title', ($item->isSample ? '［示例內容］' : '').$item->title);
            $xml->writeElement('link', $item->url());
            $xml->startElement('guid');
            $xml->writeAttribute('isPermaLink', 'true');
            $xml->text($item->url());
            $xml->endElement();
            $xml->writeElement('pubDate', $item->publishedAt->setTimezone('UTC')->toRfc2822String());
            $description = $item->excerpt;
            if ($item->type->value !== 'post') {
                $duration = DisplayTime::duration($item->video?->durationSeconds ?? $item->podcast?->durationSeconds);
                $description = '【'.$item->type->label().($duration !== null ? '．'.$duration : '').'】'.$description;
            }
            $xml->writeElement('description', $description);
            foreach ($item->categories as $category) {
                $xml->writeElement('category', $category->name);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sitemap(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $seen = [];
        foreach ($this->content->sitemapEntries() as $entry) {
            $loc = $base.$entry['path'];
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $xml->startElement('url');
            $xml->writeElement('loc', $loc);
            if ($entry['lastmod'] !== null) {
                $xml->writeElement('lastmod', DisplayTime::iso($entry['lastmod']));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
