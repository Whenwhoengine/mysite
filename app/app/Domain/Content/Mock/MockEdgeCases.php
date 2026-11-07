<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

use App\Domain\Content\ContentRepository;
use App\Domain\Content\ContentType;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\ContentPage;
use App\Domain\Content\Dto\Image;
use App\Domain\Content\Dto\PodcastInfo;
use App\Domain\Content\Dto\VideoInfo;
use App\Domain\Content\Seo\PageMetaFactory;
use App\Http\Controllers\Api\V1\PublicApiPresenter;
use App\Support\Html\Sanitizer;
use Carbon\CarbonImmutable;

/**
 * 合成邊界案例（v1 §6.8 主 CI 第 5 步、§7 F1 第 2 步列的情境）。
 *
 * 內容全部是本類別寫死的合成文字（標 is_sample: true），不取自 WordPress。
 * 為了讓形狀與真實 API 完全一致，DTO 一律經正式的 Sanitizer、MediaPolicy（經 PublicApiPresenter）
 * 與 PageMetaFactory 產生，外層包裝比照 PublicContentController；之後與真實匯出走同一個轉換層與契約驗證。
 * 「影片 ID 不合法」「音訊不可播」是把不合政策的值交給正式政策，由政策本身判定為 null，不是手寫 null。
 */
final class MockEdgeCases
{
    /** 超長標題的最少字數（任務規格：至少 200 字）。 */
    public const LONG_TITLE_MIN_CHARS = 200;

    /** 長 slug 的字數（路由與契約允許的上限 [^/]{1,200}）。 */
    public const LONG_SLUG_CHARS = 200;

    public function __construct(
        private readonly PublicApiPresenter $present,
        private readonly PageMetaFactory $meta,
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return list<array{scenario: string, kind: 'list'|'detail', api_path: string, description: string, payload: array<string, mixed>}>
     */
    public function build(): array
    {
        $cover = new Image($this->origin().'/msmock/edge-cover.png', '示例封面（邊界案例）', 1200, 630);
        $intro = '<p>本文為系統產生的示例邊界案例，只用於版面與功能測試，不代表網站作者的觀點、經歷或任何承諾。</p>';

        $longTitle = self::repeatTo('示例邊界案例：這是一個刻意加長的中文標題，用來測試卡片、標題區、麵包屑與分享區塊在各種寬度下能否正常換行而不溢出版面。', self::LONG_TITLE_MIN_CHARS + 20);
        $longUrl = 'https://example.com/msmock/'.self::repeatTo('very-long-path-segment-without-any-spaces/', 600).'end?query='.str_repeat('x', 80);
        $chineseSlug = '示例中文網址邊界案例';
        $longSlug = self::repeatTo('msmock-edge-slug-200-', self::LONG_SLUG_CHARS);

        $cases = [];
        $cases[] = $this->detailCase('long-title', '超長中文標題（'.mb_strlen($longTitle).' 字）；正文含一張圖片', $this->item(
            ContentType::Post, 'msmock-edge-long-title', $longTitle,
            '示例邊界案例：標題超過 200 字。',
            $intro.'<figure><img src="'.$this->origin().'/msmock/edge-inline.png" alt="示例圖片（邊界案例）" width="1200" height="630"><figcaption>示例圖說</figcaption></figure>',
            $cover,
        ));
        $cases[] = $this->detailCase('long-url', '超長網址（'.strlen($longUrl).' 字元、無空白）出現在摘要與正文連結', $this->item(
            ContentType::Post, 'msmock-edge-long-url', '示例邊界案例：含超長網址的文章',
            '示例邊界案例：以下是刻意很長、沒有空白的網址 '.$longUrl,
            $intro.'<p>以下是刻意很長、沒有空白的網址，用來測試窄螢幕換行：</p><p><a href="'.$longUrl.'">'.$longUrl.'</a></p>',
            $cover,
        ));
        $cases[] = $this->detailCase('no-cover', '無封面（cover 與 seo.og_image 皆為 null）', $this->item(
            ContentType::Post, 'msmock-edge-no-cover', '示例邊界案例：沒有封面的文章',
            '示例邊界案例：這篇文章沒有封面圖。',
            $intro,
            null,
        ));
        $cases[] = $this->emptyListCase();
        $cases[] = $this->detailCase('video-unavailable', '影片 ID 不合法（由 MediaPolicy 判定 video_unavailable: true）', $this->item(
            ContentType::Video, 'msmock-edge-video-unavailable', '示例邊界案例：影片目前無法播放',
            '示例邊界案例：影片 ID 不合法，前端應顯示失效狀態。',
            $intro,
            $cover,
            video: new VideoInfo('not-valid/id!', 'https://www.youtube.com/watch?v=not-valid', 120),
        ));
        $cases[] = $this->detailCase('audio-unavailable', '音訊不可播（來源不在允許清單，由 MediaPolicy 判定 audio_unavailable: true；簡介照常提供）', $this->item(
            ContentType::Podcast, 'msmock-edge-audio-unavailable', '示例邊界案例：音訊目前無法播放',
            '示例邊界案例：音訊來源不合政策，前端應顯示失效狀態並保留簡介。',
            $intro,
            $cover,
            podcast: new PodcastInfo('https://audio.example.invalid/msmock/edge.mp3', 'audio/mpeg', 600),
        ));
        $cases[] = $this->detailCase('chinese-slug', '中文 slug（path 為百分比編碼）', $this->item(
            ContentType::Post, $chineseSlug, '示例邊界案例：中文網址的文章',
            '示例邊界案例：這篇文章的 slug 是中文。',
            $intro,
            $cover,
        ));
        $cases[] = $this->detailCase('slug-200', 'slug 恰為 '.self::LONG_SLUG_CHARS.' 字', $this->item(
            ContentType::Post, $longSlug, '示例邊界案例：200 字 slug 的文章',
            '示例邊界案例：這篇文章的 slug 長度是 200 字。',
            $intro,
            $cover,
        ));

        $this->assertScenarios($cases);

        return $cases;
    }

    /** @return array{scenario: string, kind: 'detail', api_path: string, description: string, payload: array<string, mixed>} */
    private function detailCase(string $scenario, string $description, ContentItem $item): array
    {
        return [
            'scenario' => $scenario,
            'kind' => 'detail',
            'api_path' => '/api/v1'.$item->path(),
            'description' => $description,
            // 外層形狀比照 PublicContentController::detail()
            'payload' => ['data' => $this->present->detail($item), 'seo' => $this->present->seo($this->meta->item($item))],
        ];
    }

    /** @return array{scenario: string, kind: 'list', api_path: string, description: string, payload: array<string, mixed>} */
    private function emptyListCase(): array
    {
        $type = ContentType::Post;
        $page = new ContentPage([], 1, ContentRepository::DEFAULT_PER_PAGE, 0, 0);

        return [
            'scenario' => 'empty-list',
            'kind' => 'list',
            'api_path' => '/api/v1/'.$type->pathPrefix(),
            'description' => '零篇列表（文章列表第 1 頁、total 0）',
            // 外層形狀比照 PublicContentController::index()
            'payload' => [
                'data' => $this->present->summaries($page),
                'meta' => $this->present->pagination($page),
                'collection' => $this->present->collection($type),
                'seo' => $this->present->seo($this->meta->listing($type, 1)),
            ],
        ];
    }

    private function item(ContentType $type, string $slug, string $title, string $excerpt, string $bodyHtml, ?Image $cover, ?VideoInfo $video = null, ?PodcastInfo $podcast = null): ContentItem
    {
        $at = CarbonImmutable::parse('2026-11-01T00:00:00Z');

        return new ContentItem(
            id: 0,
            type: $type,
            slug: $slug,
            apiSlug: $slug,
            title: $title,
            excerpt: $excerpt,
            body: $this->sanitizer->sanitize($bodyHtml),
            authorName: '示例編輯',
            categories: [],
            tags: [],
            cover: $cover,
            publishedAt: $at,
            modifiedAt: $at,
            contentVersion: 1,
            isSample: true,
            video: $video,
            podcast: $podcast,
        );
    }

    /**
     * 確認每個情境真的是它宣稱的樣子（例如政策確實把影片判為不可播）；不符代表正式政策或形狀變了，中止。
     *
     * @param  list<array{scenario: string, kind: string, api_path: string, description: string, payload: array<string, mixed>}>  $cases
     */
    private function assertScenarios(array $cases): void
    {
        foreach ($cases as $case) {
            $data = $case['payload']['data'];
            $ok = match ($case['scenario']) {
                'long-title' => mb_strlen((string) $data['title']) >= self::LONG_TITLE_MIN_CHARS && $data['cover'] !== null && str_contains((string) $data['content_html'], '<img'),
                'long-url' => str_contains((string) $data['content_html'], 'href="https://example.com/msmock/'),
                'no-cover' => $data['cover'] === null && $case['payload']['seo']['og_image'] === null,
                'empty-list' => $data === [] && $case['payload']['meta']['total'] === 0,
                'video-unavailable' => ($data['video']['video_unavailable'] ?? null) === true && $data['video']['youtube_id'] === null,
                'audio-unavailable' => ($data['podcast']['audio_unavailable'] ?? null) === true && $data['podcast']['audio_url'] === null,
                'chinese-slug' => $data['path'] === '/articles/'.rawurlencode((string) $data['slug']) && preg_match('/^[\x00-\x7f]+$/', (string) $data['slug']) !== 1,
                'slug-200' => mb_strlen((string) $data['slug']) === self::LONG_SLUG_CHARS,
                default => false,
            };
            if (! $ok) {
                throw new MockExportException("合成邊界案例「{$case['scenario']}」與預期不符（正式政策或 API 形狀可能已變動）");
            }
        }
    }

    /** 合成封面、正文圖片用的來源：WP_PUBLIC_URL（正式政策允許的同源）；未設定時用 https 保留網域。 */
    private function origin(): string
    {
        $wp = rtrim((string) config('mysite.wp_public_url'), '/');

        return preg_match('#^https?://[^/\s]+$#', $wp) === 1 ? $wp : 'https://media.example.invalid';
    }

    /** 重複片段直到恰好 $length 字（以字元計）。 */
    private static function repeatTo(string $piece, int $length): string
    {
        return mb_substr(str_repeat($piece, intdiv($length, max(1, mb_strlen($piece))) + 1), 0, $length);
    }
}
