<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Domain\Content\ContentQueryService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Seo\PageMeta;
use App\Http\Controllers\Controller;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use App\Support\Html\Sanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 公開內容頁（UI-01、UI-06）。所有資料經 ContentQueryService 讀真實 WP；不放假資料。
 */
final class ContentController extends Controller
{
    public const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        private readonly ContentQueryService $content,
        private readonly Sanitizer $sanitizer,
    ) {}

    public function home(): Response
    {
        $home = $this->content->home();
        $site = $this->content->site();
        $siteName = $site?->name ?? (string) config('mysite.site_name');

        return response()->view('content.home', $home + [
            'site' => $site,
            'meta' => new PageMeta(
                $siteName,
                PageMeta::describe($site?->tagline, $siteName.'：影片、Podcast 與文章。'),
                PageMeta::canonicalUrl('/'),
            ),
        ]);
    }

    public function index(Request $request): Response
    {
        $type = $this->routeType($request);
        $page = $this->pageNumber($request);
        $result = $this->content->listing($type, $page);
        $title = $type->collectionTitle().($page > 1 ? "（第 {$page} 頁）" : '');

        return response()->view('content.index', [
            'type' => $type,
            'result' => $result,
            'meta' => new PageMeta(
                $title,
                PageMeta::describe($type->collectionLede(), $type->collectionTitle().'列表'),
                PageMeta::canonicalUrl('/'.$type->pathPrefix(), ['page' => $page]),
            ),
        ]);
    }

    public function show(Request $request): Response
    {
        $type = $this->routeType($request);

        return $this->detail($type, (string) $request->route('slug'));
    }

    public function page(Request $request): Response
    {
        return $this->detail(ContentType::Page, (string) $request->route('slug'));
    }

    public function category(Request $request): Response
    {
        return $this->taxonomy($request, 'category');
    }

    public function tag(Request $request): Response
    {
        return $this->taxonomy($request, 'tag');
    }

    public function search(Request $request): Response
    {
        $rawQ = $request->query('q');
        $q = is_string($rawQ) ? trim((string) preg_replace('/\s+/u', ' ', $rawQ)) : '';
        $rawType = $request->query('type');
        $type = is_string($rawType) && $rawType !== '' ? ContentType::tryFrom($rawType) : null;
        $page = $this->pageNumber($request);

        $error = null;
        $result = null;
        $status = 200;
        if (is_string($rawType) && $rawType !== '' && $type === null) {
            $error = '內容類型不正確，請重新選擇。';
            $status = 422;
        } elseif (mb_strlen($q) > self::SEARCH_MAX_LENGTH) {
            $error = '搜尋字詞最多 '.self::SEARCH_MAX_LENGTH.' 個字，請縮短後再試。';
            $status = 422;
        } elseif ($q !== '') {
            $result = $this->content->search($q, $type, $page);
        }

        return response()->view('content.search', [
            'q' => $q,
            'type' => $type,
            'result' => $result,
            'error' => $error,
            'meta' => new PageMeta(
                $q !== '' ? '搜尋「'.mb_strimwidth($q, 0, 40, '…').'」'.($page > 1 ? "（第 {$page} 頁）" : '') : '搜尋',
                '搜尋站內文章、影片與 Podcast。',
                PageMeta::canonicalUrl('/search'),
                noindex: true,
            ),
        ], $status);
    }

    private function detail(ContentType $type, string $slug): Response
    {
        $item = $this->content->detail($type, $slug);

        return response()->view('content.show', [
            'item' => $item,
            'meta' => $this->itemMeta($item),
        ]);
    }

    /** @param 'category'|'tag' $kind */
    private function taxonomy(Request $request, string $kind): Response
    {
        $term = $this->content->term($kind, (string) $request->route('slug'));
        $rawType = $request->query('type');
        $label = $kind === 'category' ? '分類' : '標籤';
        $path = '/'.($kind === 'category' ? 'categories' : 'tags').'/'.rawurlencode($term->slug);

        if ($rawType === null) {
            if ($request->query('page') !== null) {
                throw new MysiteException(ErrorCode::ContentNotFound, 'taxonomy_overview_paged');
            }

            return response()->view('content.taxonomy', [
                'kind' => $kind,
                'label' => $label,
                'term' => $term,
                'basePath' => $path,
                'overview' => $this->content->termOverview($kind, $term),
                'type' => null,
                'result' => null,
                'meta' => new PageMeta("{$label}：{$term->name}", "{$label}「{$term->name}」的文章、影片與 Podcast。", PageMeta::canonicalUrl($path)),
            ]);
        }

        $type = is_string($rawType) ? ContentType::tryFrom($rawType) : null;
        if ($type === null || ! in_array($type, ContentType::listable(), true)) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'taxonomy_type');
        }
        $page = $this->pageNumber($request);
        $result = $this->content->termListing($kind, $term, $type, $page);

        return response()->view('content.taxonomy', [
            'kind' => $kind,
            'label' => $label,
            'term' => $term,
            'basePath' => $path,
            'overview' => null,
            'type' => $type,
            'result' => $result,
            'meta' => new PageMeta(
                "{$label}：{$term->name}－{$type->collectionTitle()}".($page > 1 ? "（第 {$page} 頁）" : ''),
                "{$label}「{$term->name}」的{$type->label()}。",
                PageMeta::canonicalUrl($path, ['type' => $type->value, 'page' => $page]),
            ),
        ]);
    }

    private function itemMeta(ContentItem $item): PageMeta
    {
        $cover = $item->cover !== null && $this->sanitizer->isAllowedMediaUrl($item->cover->url) ? $item->cover : null;

        return new PageMeta(
            $item->title,
            PageMeta::describe($item->excerpt !== '' ? $item->excerpt : $item->body?->plainText(), $item->title),
            PageMeta::canonicalUrl($item->path()),
            ogType: match ($item->type) {
                ContentType::Video => 'video.other',
                ContentType::Page => 'website',
                default => 'article',
            },
            ogImage: $cover?->url,
            ogImageAlt: $cover?->alt,
        );
    }

    private function routeType(Request $request): ContentType
    {
        return ContentType::from((string) $request->route('type'));
    }

    /** page 參數：1–1000 的正整數；其他值一律 404（不送往上游，canonical 唯一）。 */
    private function pageNumber(Request $request): int
    {
        $raw = $request->query('page');
        if ($raw === null) {
            return 1;
        }
        if (! is_string($raw) || preg_match('/^[1-9]\d{0,3}$/D', $raw) !== 1 || (int) $raw > 1000) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'page_param');
        }

        return (int) $raw;
    }
}
