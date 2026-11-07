<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Domain\Content\ContentQueryService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Seo\PageMetaFactory;
use App\Http\Controllers\Controller;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 公開內容頁（UI-01、UI-06）。所有資料經 ContentQueryService 讀真實 WP；不放假資料。
 */
final class ContentController extends Controller
{
    public const SEARCH_MAX_LENGTH = PublicContentInput::SEARCH_MAX_LENGTH;

    public function __construct(
        private readonly ContentQueryService $content,
        private readonly PageMetaFactory $meta,
    ) {}

    public function home(): Response
    {
        $home = $this->content->home();
        $site = $this->content->site();

        return response()->view('content.home', $home + [
            'site' => $site,
            'meta' => $this->meta->home($site),
        ]);
    }

    public function index(Request $request): Response
    {
        $type = $this->routeType($request);
        $page = PublicContentInput::page($request);
        $result = $this->content->listing($type, $page);

        return response()->view('content.index', [
            'type' => $type,
            'result' => $result,
            'meta' => $this->meta->listing($type, $page),
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
        ['q' => $q, 'type' => $type, 'page' => $page, 'error' => $error] = PublicContentInput::search($request);

        $result = null;
        $status = $error !== null ? 422 : 200;
        if ($error === null && $q !== '') {
            $result = $this->content->search($q, $type, $page);
        }

        return response()->view('content.search', [
            'q' => $q,
            'type' => $type,
            'result' => $result,
            'error' => $error,
            'meta' => $this->meta->search($q, $page),
        ], $status);
    }

    private function detail(ContentType $type, string $slug): Response
    {
        $item = $this->content->detail($type, $slug);

        return response()->view('content.show', [
            'item' => $item,
            'meta' => $this->meta->item($item),
        ]);
    }

    /** @param 'category'|'tag' $kind */
    private function taxonomy(Request $request, string $kind): Response
    {
        $term = $this->content->term($kind, (string) $request->route('slug'));
        $rawType = $request->query('type');
        $label = PageMetaFactory::taxonomyLabel($kind);
        $path = PageMetaFactory::taxonomyPath($kind, $term);

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
                'meta' => $this->meta->taxonomyOverview($kind, $term),
            ]);
        }

        $type = is_string($rawType) ? ContentType::tryFrom($rawType) : null;
        if ($type === null || ! in_array($type, ContentType::listable(), true)) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'taxonomy_type');
        }
        $page = PublicContentInput::page($request);
        $result = $this->content->termListing($kind, $term, $type, $page);

        return response()->view('content.taxonomy', [
            'kind' => $kind,
            'label' => $label,
            'term' => $term,
            'basePath' => $path,
            'overview' => null,
            'type' => $type,
            'result' => $result,
            'meta' => $this->meta->taxonomyListing($kind, $term, $type, $page),
        ]);
    }

    private function routeType(Request $request): ContentType
    {
        return ContentType::from((string) $request->route('type'));
    }
}
