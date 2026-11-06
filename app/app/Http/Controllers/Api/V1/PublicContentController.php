<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\ContentQueryService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Seo\PageMetaFactory;
use App\Http\Controllers\Content\PublicContentInput;
use App\Http\Controllers\Controller;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\ErrorRenderer;
use App\Support\Errors\MysiteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 公開唯讀 JSON API（MySite React 前端專案 v1 §5、F2）。
 *
 * 與 Blade 公開頁走同一條路：ContentQueryService → ContentRepository → WordPressRestClient，
 * 可見性證明、淨化、錯誤分類全部重用；不另寫讀 WordPress 的路，沿用既有內容快取與可見性租約、不另加快取。參數規則與 Blade 相同（PublicContentInput）。
 */
final class PublicContentController extends Controller
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly ContentQueryService $content,
        private readonly PageMetaFactory $meta,
        private readonly PublicApiPresenter $present,
    ) {}

    /** 網站資訊與選單：WordPress 讀不到時用內建後備，不回 503（v1 §5.2）。 */
    public function site(): JsonResponse
    {
        return $this->json(['data' => $this->present->site(
            $this->content->site(),
            $this->content->primaryMenu(),
            $this->content->footerMenu(),
        )]);
    }

    public function home(): JsonResponse
    {
        $home = $this->content->home();
        $site = $this->content->site();

        return $this->json([
            'data' => [
                'featured' => $home['featured'] !== null ? $this->present->summary($home['featured']) : null,
                'posts' => $this->present->summaries($home['posts']),
                'videos' => $this->present->summaries($home['videos']),
                'podcasts' => $this->present->summaries($home['podcasts']),
            ],
            'seo' => $this->present->seo($this->meta->home($site)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $type = ContentType::from((string) $request->route('type'));
        $page = PublicContentInput::page($request);
        $result = $this->content->listing($type, $page);

        return $this->json([
            'data' => $this->present->summaries($result),
            'meta' => $this->present->pagination($result),
            'collection' => $this->present->collection($type),
            'seo' => $this->present->seo($this->meta->listing($type, $page)),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->detail(ContentType::from((string) $request->route('type')), (string) $request->route('slug'));
    }

    public function page(Request $request): JsonResponse
    {
        return $this->detail(ContentType::Page, (string) $request->route('slug'));
    }

    public function category(Request $request): JsonResponse
    {
        return $this->taxonomy($request, 'category');
    }

    public function tag(Request $request): JsonResponse
    {
        return $this->taxonomy($request, 'tag');
    }

    public function search(Request $request): JsonResponse
    {
        ['q' => $q, 'type' => $type, 'page' => $page, 'error' => $error] = PublicContentInput::search($request);
        if ($error !== null) {
            $response = ErrorRenderer::respond($request, 422, 'SEARCH_INVALID', $error);
            assert($response instanceof JsonResponse);

            return $response;
        }
        $result = $q !== '' ? $this->content->search($q, $type, $page) : null;

        return $this->json([
            'data' => [
                'q' => $q,
                'type' => $type?->value,
                'searched' => $result !== null,
                'items' => $result !== null ? $this->present->summaries($result) : [],
            ],
            'meta' => $result !== null ? $this->present->pagination($result) : null,
            'seo' => $this->present->seo($this->meta->search($q, $page)),
        ]);
    }

    private function detail(ContentType $type, string $slug): JsonResponse
    {
        $item = $this->content->detail($type, $slug);

        return $this->json([
            'data' => $this->present->detail($item),
            'seo' => $this->present->seo($this->meta->item($item)),
        ]);
    }

    /** @param 'category'|'tag' $kind */
    private function taxonomy(Request $request, string $kind): JsonResponse
    {
        $term = $this->content->term($kind, (string) $request->route('slug'));
        $rawType = $request->query('type');
        $base = [
            'kind' => $kind,
            'label' => PageMetaFactory::taxonomyLabel($kind),
            'term' => $this->present->term($kind, $term),
        ];

        if ($rawType === null) {
            if ($request->query('page') !== null) {
                throw new MysiteException(ErrorCode::ContentNotFound, 'taxonomy_overview_paged');
            }
            $overview = [];
            foreach ($this->content->termOverview($kind, $term) as $typeValue => $section) {
                $overview[$typeValue] = ['items' => $this->present->summaries($section), 'total' => $section->total, 'per_page' => $section->perPage];
            }

            return $this->json([
                'data' => $base + ['mode' => 'overview', 'type' => null, 'overview' => $overview, 'items' => null],
                'meta' => null,
                'seo' => $this->present->seo($this->meta->taxonomyOverview($kind, $term)),
            ]);
        }

        $type = is_string($rawType) ? ContentType::tryFrom($rawType) : null;
        if ($type === null || ! in_array($type, ContentType::listable(), true)) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'taxonomy_type');
        }
        $page = PublicContentInput::page($request);
        $result = $this->content->termListing($kind, $term, $type, $page);

        return $this->json([
            'data' => $base + ['mode' => 'listing', 'type' => $type->value, 'overview' => null, 'items' => $this->present->summaries($result)],
            'meta' => $this->present->pagination($result),
            'seo' => $this->present->seo($this->meta->taxonomyListing($kind, $term, $type, $page)),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload, 200, [], self::JSON_FLAGS);
    }
}
