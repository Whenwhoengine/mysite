<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\ContentType;
use App\Domain\Content\Seo\PageMeta;
use App\Domain\Content\Support\DisplayTime;
use App\Domain\Content\Support\Slug;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** slug 參數有界（API-03）、canonical 唯一（UI-06）、UTC→Asia/Taipei 顯示（UI-01）。 */
final class SlugAndDisplayTimeTest extends TestCase
{
    #[Test]
    #[Group('API-03')]
    public function slugs_are_bounded_and_normalized_for_the_api(): void
    {
        $this->assertSame('hello-world', Slug::toApi('hello-world'));
        $this->assertSame('%e4%b8%ad%e6%96%87-slug', Slug::toApi('中文-slug'));
        $this->assertSame('%e4%b8%ad', Slug::toApi('%e4%b8%ad'));
        $this->assertSame('中文-slug', Slug::fromApi('%e4%b8%ad%e6%96%87-slug'));
        foreach (['', 'Hello', 'a_b', 'a.b', 'a/b', '../x', 'a b', 'a?b=1', str_repeat('a', 191), "a\0b", "\xff"] as $bad) {
            $this->assertNull(Slug::toApi($bad), '應拒絕：'.bin2hex($bad));
        }
        $this->assertSame('/articles/%E4%B8%AD%E6%96%87', ContentType::Post->path('中文'));
    }

    #[Test]
    #[Group('UI-06')]
    public function canonical_urls_use_app_url_and_only_page_above_one(): void
    {
        config(['app.url' => 'https://www.example.org/']);

        $this->assertSame('https://www.example.org/articles', PageMeta::canonicalUrl('/articles', ['page' => 1]));
        $this->assertSame('https://www.example.org/articles?page=2', PageMeta::canonicalUrl('/articles', ['page' => 2]));
        $this->assertSame('https://www.example.org/categories/news?page=3&type=video', PageMeta::canonicalUrl('/categories/news', ['type' => 'video', 'page' => 3]));
        $this->assertSame('https://www.example.org/', PageMeta::canonicalUrl('/'));
        $this->assertSame('標題｜站名', (new PageMeta('標題', '', null))->fullTitle('站名'));
        $this->assertSame('站名', (new PageMeta('站名', '', null))->fullTitle('站名'));
        $this->assertSame(150, mb_strlen(PageMeta::describe(str_repeat('字', 300), 'x')));
    }

    #[Test]
    #[Group('UI-01')]
    public function times_are_parsed_as_utc_and_displayed_in_taipei(): void
    {
        config(['mysite.display_timezone' => 'Asia/Taipei']);
        $utc = CarbonImmutable::parse('2026-11-04T17:30:00Z');

        $this->assertSame('2026年11月5日', DisplayTime::date($utc));
        $this->assertSame('2026年11月5日 01:30', DisplayTime::dateTime($utc));
        $this->assertSame('2026-11-04T17:30:00Z', DisplayTime::iso($utc->setTimezone('Asia/Taipei')));
        $this->assertSame('4:05', DisplayTime::duration(245));
        $this->assertSame('1:00:05', DisplayTime::duration(3605));
        $this->assertNull(DisplayTime::duration(0));
        $this->assertSame('PT4M5S', DisplayTime::isoDuration(245));
        $this->assertSame('1 小時', DisplayTime::durationSpoken(3605));
        $this->assertSame('4 分 5 秒', DisplayTime::durationSpoken(245));
    }
}
