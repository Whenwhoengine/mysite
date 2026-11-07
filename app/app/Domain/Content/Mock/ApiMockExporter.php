<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

use App\Domain\Content\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 假資料匯出（MySite React 前端專案 v1 §7 F2 第 5 步、定案執行書 §5 M-19）。
 *
 * 1. 在 Laravel 行程內以 HTTP kernel 直接呼叫公開 API（不經網路、不經 gateway），依序取：
 *    site → home → 三種列表第 1 頁 → 列表中每一篇的單篇 → 選單找得到的一般頁面 → 分類／標籤總覽 → 搜尋（空查詢＋一個有結果的查詢）。
 * 2. 每個回應先以契約驗證，再交 MockTransformer（只收示例內容、媒體改寫、哨兵字串），轉換後再以契約驗證一次。
 * 3. 附上 MockEdgeCases 的合成邊界案例（同一轉換層與契約驗證）。
 * 4. 全部在記憶體中完成且通過檢查後才寫檔；任何一步失敗都不寫入任何檔案（不留下半套假資料）。
 *
 * 輸出（<out> 為 --out）：
 *   <out>/mocks/*.json、<out>/mocks/manifest.json、<out>/public/mock-media/*（合成媒體）。
 * 本工具不刪除、不覆寫任何既有檔案：mocks/ 或 public/mock-media/ 已有內容時直接中止。
 */
final class ApiMockExporter
{
    public const FORMAT = 'mysite-api-mocks/1';

    /** 允許執行的 APP_ENV；staging／production 一律拒絕（假資料工具只在開發與測試環境使用）。 */
    public const ALLOWED_ENVIRONMENTS = ['local', 'development', 'testing'];

    /** 找有結果的搜尋時第一個嘗試的查詢詞（示例內容的標題與摘要都含「示例」）。 */
    public const DEFAULT_SEARCH_QUERY = '示例';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @var array<string, array{entry: array<string, mixed>, payload: array<string, mixed>}> 輸出檔（相對 <out>）→ 內容 */
    private array $files = [];

    /** @var list<array{api_path: string, reason: string}> */
    private array $skipped = [];

    private MockContract $contract;

    public function __construct(
        private readonly Application $app,
        private readonly MockTransformer $transformer,
        private readonly MockEdgeCases $edges,
    ) {}

    /**
     * @return array{out: string, exported: int, edge: int, media: int, skipped: list<array{api_path: string, reason: string}>, search_query: string}
     *
     * @throws MockExportException
     */
    public function export(string $out): array
    {
        $this->files = [];
        $this->skipped = [];
        $env = (string) $this->app->environment();
        if (! in_array($env, self::ALLOWED_ENVIRONMENTS, true)) {
            throw MockExportException::forbidden('假資料匯出只允許在 '.implode('／', self::ALLOWED_ENVIRONMENTS)." 環境執行（目前 APP_ENV={$env}）");
        }
        $root = $this->resolveOutput($out);
        $this->contract = MockContract::default();

        $site = $this->fetch('/api/v1/site', 'site', 'mocks/site.json');
        $this->fetch('/api/v1/home', 'home', 'mocks/home.json');

        $terms = [];
        $firstTitle = null;
        foreach (ContentType::listable() as $type) {
            $prefix = $type->pathPrefix();
            $list = $this->fetch("/api/v1/{$prefix}", 'list', "mocks/{$prefix}/_index.json");
            foreach ($list['data'] as $summary) {
                $firstTitle ??= (string) $summary['title'];
                $detail = $this->fetch('/api/v1'.$summary['path'], 'detail', "mocks/{$prefix}/".self::fileName((string) $summary['slug']).'.json');
                foreach (['categories', 'tags'] as $kind) {
                    foreach ($detail['data'][$kind] as $term) {
                        $terms[$term['path']] = [$kind, (string) $term['slug']];
                    }
                }
            }
        }

        foreach ($this->menuPages($site) as $slug => $path) {
            $this->fetch('/api/v1'.$path, 'detail', 'mocks/pages/'.self::fileName($slug).'.json', skipNotFound: true);
        }

        ksort($terms);
        foreach ($terms as $path => [$kind, $slug]) {
            $this->fetch('/api/v1'.$path, 'taxonomy', "mocks/{$kind}/".self::fileName($slug).'.json');
        }

        $this->fetch('/api/v1/search', 'search', 'mocks/search/empty.json');
        $query = $this->searchWithResults(array_values(array_unique(array_filter([self::DEFAULT_SEARCH_QUERY, $firstTitle !== null ? mb_substr($firstTitle, 0, 20) : null]))));

        $exported = count($this->files);
        foreach ($this->edges->build() as $case) {
            $file = "mocks/edge/{$case['scenario']}.json";
            $payload = $this->convert($case['kind'], $case['payload'], $case['api_path'], $file);
            $this->add($file, $payload, [
                'kind' => 'edge',
                'scenario' => $case['scenario'],
                'description' => $case['description'],
                'api_path' => $case['api_path'],
                'requested_at' => null,
            ]);
        }

        $this->write($root, $query);

        return [
            'out' => $root,
            'exported' => $exported,
            'edge' => count($this->files) - $exported,
            'media' => count(MockMedia::files()),
            'skipped' => $this->skipped,
            'search_query' => $query,
        ];
    }

    /** slug → 安全檔名：[a-z0-9-] 的短 slug 原樣使用；其餘（中文、過長、其他字元）用雜湊，對照寫在 manifest。 */
    public static function fileName(string $slug): string
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug) === 1 ? $slug : '_slug-'.substr(hash('sha256', $slug), 0, 16);
    }

    /**
     * 取一個 API 回應 → 契約驗證 → 轉換 → 契約再驗證 → 排入輸出。
     *
     * @return array<string, mixed> 原始（未轉換）回應，供後續步驟取 slug、path
     */
    private function fetch(string $apiPath, string $kind, string $file, bool $skipNotFound = false): array
    {
        [$response, $requestedAt] = $this->call($apiPath);

        return $this->accept($apiPath, $response, $requestedAt, $kind, $file, $skipNotFound);
    }

    /** @return array<string, mixed> 原始（未轉換）回應 */
    private function accept(string $apiPath, Response $response, string $requestedAt, string $kind, string $file, bool $skipNotFound = false): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        if ($status === 404 && $skipNotFound) {
            $this->skipped[] = ['api_path' => $apiPath, 'reason' => '404（'.(string) (json_decode($body, true)['error'] ?? '?').'）'];

            return [];
        }
        if ($status !== 200) {
            $code = json_decode($body, true)['error'] ?? '?';
            throw new MockExportException("GET {$apiPath} 回 {$status}（{$code}）；假資料需要每個端點都正常回應");
        }
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'application/json')) {
            throw new MockExportException("GET {$apiPath} 不是 JSON 回應");
        }
        $operationPath = MockContract::operationPath($apiPath);
        $this->contract->validate($body, $operationPath, $status, "API 回應 GET {$apiPath}");
        $raw = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($raw)) {
            throw new MockExportException("GET {$apiPath} 回應不是物件");
        }

        $this->add($file, $this->convert($kind, $raw, $apiPath, $file), [
            'kind' => 'export',
            'api_path' => $apiPath,
            'requested_at' => $requestedAt,
        ]);

        return $raw;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function convert(string $kind, array $payload, string $apiPath, string $file): array
    {
        $converted = $this->transformer->transform($kind, $payload, $file);
        $this->contract->validate($converted, MockContract::operationPath($apiPath), 200, "假資料 {$file}");

        return $converted;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $entry */
    private function add(string $file, array $payload, array $entry): void
    {
        if (isset($this->files[$file])) {
            throw new MockExportException("輸出檔名重複：{$file}");
        }
        $operationPath = MockContract::operationPath((string) $entry['api_path']);
        $this->files[$file] = [
            'entry' => ['file' => $file] + $entry + [
                'operation_id' => $this->contract->operationId($operationPath),
                'operation_path' => $operationPath,
                'method' => 'GET',
                'status' => 200,
            ],
            'payload' => $payload,
        ];
    }

    /** @param list<string> $candidates @return string 實際使用的查詢詞 */
    private function searchWithResults(array $candidates): string
    {
        foreach ($candidates as $q) {
            $apiPath = '/api/v1/search?q='.rawurlencode($q);
            [$response, $requestedAt] = $this->call($apiPath);
            $items = json_decode((string) $response->getContent(), true)['data']['items'] ?? [];
            if ($response->getStatusCode() === 200 && is_array($items) && $items !== []) {
                $this->accept($apiPath, $response, $requestedAt, 'search', 'mocks/search/results.json');

                return $q;
            }
        }

        throw new MockExportException('找不到有結果的搜尋（試過：'.implode('、', $candidates).'）；請確認 WordPress 有已發布的示例內容');
    }

    /**
     * 站內選單（主選單＋頁尾）中指向 /pages/{slug} 的站內連結。
     *
     * @param  array<string, mixed>  $site
     * @return array<string, string> slug（解碼後）→ 站內路徑
     */
    private function menuPages(array $site): array
    {
        $pages = [];
        foreach (['primary', 'footer'] as $menu) {
            foreach ($site['data']['menus'][$menu] ?? [] as $item) {
                $url = (string) ($item['url'] ?? '');
                if (($item['external'] ?? true) === false && preg_match('#^/pages/([^/?\#]+)$#D', $url, $m) === 1) {
                    $pages[rawurldecode($m[1])] = $url;
                }
            }
        }
        if ($pages === []) {
            $this->skipped[] = ['api_path' => '/api/v1/pages/{slug}', 'reason' => '站內選單沒有指向 /pages/{slug} 的連結，未匯出一般頁面'];
        }

        return $pages;
    }

    /** @return array{Response, string} 回應與請求時間（UTC） */
    private function call(string $uri): array
    {
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $requestedAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [$response, $requestedAt];
    }

    /** 驗證輸出位置（不建立任何東西）：相對路徑以 Laravel 專案根為準；mocks/、public/mock-media/ 必須不存在或為空目錄。 */
    private function resolveOutput(string $out): string
    {
        $out = trim($out);
        if ($out === '' || str_contains($out, "\0")) {
            throw MockExportException::usage('必須以 --out=<目錄> 指定輸出位置');
        }
        $root = str_starts_with($out, '/') ? $out : base_path($out);
        $root = rtrim($root, '/');
        if (file_exists($root) && ! is_dir($root)) {
            throw MockExportException::usage("--out 不是目錄：{$root}");
        }
        foreach (['mocks', 'public/mock-media'] as $sub) {
            $dir = "{$root}/{$sub}";
            if (is_link($dir) || (file_exists($dir) && (! is_dir($dir) || (new \FilesystemIterator($dir))->valid()))) {
                throw MockExportException::usage("輸出位置已有內容：{$dir}；本工具不覆寫也不刪除既有檔案，請指定新的 --out 目錄，或自行移除舊的匯出結果後再執行");
            }
        }

        return $root;
    }

    private function write(string $root, string $query): void
    {
        $media = [];
        foreach (MockMedia::files() as $name => $file) {
            $path = "public/mock-media/{$name}";
            $this->put($root, $path, $file['bytes']);
            $media[] = ['file' => $path, 'url' => MockMedia::url($name), 'mime' => $file['mime'], 'bytes' => strlen($file['bytes']), 'sha256' => hash('sha256', $file['bytes']), 'description' => $file['description']];
        }

        $entries = [];
        foreach ($this->files as $path => $file) {
            $json = json_encode($file['payload'], self::JSON_FLAGS)."\n";
            $this->put($root, $path, $json);
            $entries[] = $file['entry'] + ['sha256' => hash('sha256', $json)];
        }

        $manifest = [
            'format' => self::FORMAT,
            'description' => 'mysite 公開 API 假資料（前端假資料模式用）。只含示例內容；媒體網址已改寫為 /mock-media/ 的合成檔；每份檔案都含哨兵字串。檔案路徑皆相對於匯出根目錄（與前端儲存庫根目錄的 mocks/、public/mock-media/ 對應）。',
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'app_env' => (string) $this->app->environment(),
            'sentinel' => MockTransformer::SENTINEL,
            'contract' => ['file' => 'app/'.MockContract::FILE, 'version' => $this->contract->version(), 'sha256' => $this->contract->sha256()],
            'rules' => [
                'only_sample_content' => true,
                'media_base' => '/mock-media/',
                'rewritten_fields' => ['*.cover.url', 'site.logo.url', 'seo.og_image', 'podcast.audio_url（audio_mime 改為 audio/wav）', 'content_html 內 <img src>'],
                'kept_fields' => ['video.watch_url', 'video.embed_url'],
                'sentinel_fields' => ['site.tagline', '*.excerpt', '*.content_html（開頭加 <p> 段落）', 'seo.description'],
                'revalidated_against_contract' => true,
            ],
            'search_query' => $query,
            'skipped' => $this->skipped,
            'files' => $entries,
            'media' => $media,
        ];
        $this->put($root, 'mocks/manifest.json', json_encode($manifest, self::JSON_FLAGS)."\n");
    }

    private function put(string $root, string $relative, string $contents): void
    {
        $path = "{$root}/{$relative}";
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new MockExportException("無法建立目錄：{$dir}");
        }
        if (file_exists($path)) {
            throw new MockExportException("檔案已存在，不覆寫：{$path}");
        }
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new MockExportException("寫入失敗：{$path}");
        }
    }
}
