<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Content\Media\MediaPolicy;
use App\Domain\Content\Mock\ApiMockExporter;
use App\Domain\Content\Mock\MockMedia;
use App\Domain\Content\Mock\MockTransformer;
use App\Support\Html\Sanitizer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 假資料匯出工具（MySite React 前端專案 v1 §7 F2 第 5 步、定案執行書 §5 M-19）。
 *
 * 不使用 RefreshDatabase／DatabaseTransactions：匯出流程只讀公開 API（快取為 array 驅動），不寫資料庫。
 * 輸出寫在 storage/framework/testing/ 底下本測試自建的暫存目錄，tearDown 只刪除該目錄。
 */
final class ApiMockExportTest extends TestCase
{
    use FakesWordPress;

    private const SENTINEL = 'MSMOCK-7F3A9C1E';

    private const TMP_PREFIX = 'framework/testing/api-mock-export-';

    private string $out;

    private static ?ValidatorInterface $contract = null;

    /** @var array<string, mixed> */
    private static ?array $spec = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        Sleep::fake();
        $this->out = storage_path(self::TMP_PREFIX.bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        // 只清掉本測試自建的暫存目錄
        if (str_contains($this->out, '/'.self::TMP_PREFIX) && is_dir($this->out)) {
            (new Filesystem)->deleteDirectory($this->out);
        }
        parent::tearDown();
    }

    #[Test]
    public function exports_sample_content_with_rewritten_media_sentinel_and_valid_contract(): void
    {
        $this->fakeSampleWordPress();
        $originalHtml = (string) $this->get('/api/v1/articles/sample-article')->assertOk()->json('data.content_html');
        $this->assertStringContainsString('src="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/inline.png"', $originalHtml, '前提：真實 API 的正文含 CMS 圖片');

        $this->assertSame(0, $this->export(), Artisan::output());
        $manifest = $this->readJson('mocks/manifest.json');
        $this->assertSame(ApiMockExporter::FORMAT, $manifest['format']);
        $this->assertSame(self::SENTINEL, $manifest['sentinel']);
        $this->assertSame(MockTransformer::SENTINEL, self::SENTINEL, '哨兵字串是固定值，不得更改');
        $this->assertSame('示例', $manifest['search_query']);
        $this->assertSame([['api_path' => '/api/v1/pages/missing', 'reason' => '404（CONTENT_NOT_FOUND）']], $manifest['skipped'], '選單上找不到的頁面略過並記錄');

        // manifest 與實際檔案一一對應
        $listed = array_column($manifest['files'], 'file');
        $actual = $this->filesUnder('mocks');
        $this->assertEqualsCanonicalizing(array_merge($listed, ['mocks/manifest.json']), $actual);
        foreach ([
            'mocks/site.json', 'mocks/home.json',
            'mocks/articles/_index.json', 'mocks/articles/sample-article.json', 'mocks/articles/second-article.json',
            'mocks/videos/_index.json', 'mocks/videos/my-video.json',
            'mocks/podcasts/_index.json', 'mocks/podcasts/ep-1.json',
            'mocks/pages/about.json', 'mocks/pages/privacy.json',
            'mocks/categories/news.json', 'mocks/categories/media.json', 'mocks/tags/demo.json',
            'mocks/search/empty.json', 'mocks/search/results.json',
        ] as $expected) {
            $this->assertContains($expected, $listed);
        }
        $edge = array_values(array_filter($manifest['files'], static fn (array $f): bool => $f['kind'] === 'edge'));
        $this->assertEqualsCanonicalizing(
            ['long-title', 'long-url', 'no-cover', 'empty-list', 'video-unavailable', 'audio-unavailable', 'chinese-slug', 'slug-200'],
            array_column($edge, 'scenario'),
        );

        // 每一份檔案：契約驗證、哨兵、只含示例內容、媒體只指向實際存在的合成檔
        $mediaFiles = $this->filesUnder('public/mock-media');
        $this->assertNotEmpty($mediaFiles);
        foreach ($manifest['files'] as $entry) {
            $raw = (string) file_get_contents($this->out.'/'.$entry['file']);
            $this->assertSame(hash('sha256', $raw), $entry['sha256'], "{$entry['file']} 雜湊");
            $this->assertSame(200, $entry['status']);
            $this->assertSame('GET', $entry['method']);
            $this->assertSame($entry['operation_id'], self::spec()['paths'][$entry['operation_path']]['get']['operationId'] ?? null, "{$entry['file']} operation");
            $this->assertTrue($this->contract()->validate(new Response($raw, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']), $entry['operation_path'], 'get'), "{$entry['file']} 通過契約");
            $this->assertStringContainsString(self::SENTINEL, $raw, "{$entry['file']} 含哨兵字串");
            $this->assertStringNotContainsString('wp-content/uploads', $raw, "{$entry['file']} 不得留下 CMS 媒體網址");
            $this->assertStringNotContainsString('audio.example.invalid', $raw);
            if ($entry['kind'] === 'export') {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $entry['requested_at']);
                $this->assertStringStartsWith('/api/v1/', $entry['api_path']);
            }
            $this->assertMockNode(json_decode($raw, true), $entry['file'], $mediaFiles);
        }

        // 正文只改了 <img src> 並在最前面加哨兵段落，其他標記原樣保留
        $article = $this->readJson('mocks/articles/sample-article.json');
        $this->assertSame(
            '<p>'.self::SENTINEL.'</p>'.str_replace('http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/inline.png', '/mock-media/'.MockMedia::INLINE, $originalHtml),
            $article['data']['content_html'],
        );
        $this->assertSame(self::SENTINEL.' 這是一段示例摘要。', $article['data']['excerpt']);
        $this->assertSame('/mock-media/'.MockMedia::COVER, $article['data']['cover']['url']);
        $this->assertSame('/mock-media/'.MockMedia::COVER, $article['seo']['og_image']);
        $this->assertNull($this->readJson('mocks/articles/second-article.json')['data']['cover']);

        $site = $this->readJson('mocks/site.json');
        $this->assertSame(self::SENTINEL.' 影音創作紀錄', $site['data']['tagline']);
        $this->assertSame('/mock-media/'.MockMedia::LOGO, $site['data']['logo']['url']);

        // YouTube 網址保留；音訊改指合成 WAV
        $video = $this->readJson('mocks/videos/my-video.json')['data']['video'];
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video['watch_url']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', $video['embed_url']);
        $podcast = $this->readJson('mocks/podcasts/ep-1.json')['data']['podcast'];
        $this->assertSame('/mock-media/'.MockMedia::AUDIO, $podcast['audio_url']);
        $this->assertSame('audio/wav', $podcast['audio_mime']);

        $this->assertFalse($this->readJson('mocks/search/empty.json')['data']['searched']);
        $results = $this->readJson('mocks/search/results.json')['data'];
        $this->assertSame('示例', $results['q']);
        $this->assertNotEmpty($results['items']);

        $this->assertEdgeCases($manifest);
        $this->assertSyntheticMedia();
    }

    /** @return array<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function nonSampleContent(): array
    {
        return [
            '網站資訊不是示例' => [fn (array $d): array => ['site' => array_merge($d['site'], ['is_sample' => false])] + $d, 'mocks/site.json'],
            // 首頁比列表先取，首頁的影片區塊就會攔下
            '影片不是示例' => [fn (array $d): array => ['video' => array_merge($d['video'], ['is_sample' => false])] + $d, 'mocks/home.json data.videos[0]'],
            '選單頁面不是示例' => [fn (array $d): array => ['about' => array_merge($d['about'], ['is_sample' => false])] + $d, 'mocks/pages/about.json'],
            '搜尋結果混入非示例' => [fn (array $d): array => ['search' => [self::wpItem(['id' => 999, 'slug' => 'real-work', 'title' => '委託人作品', 'is_sample' => false])]] + $d, 'mocks/search/results.json'],
        ];
    }

    #[Test]
    #[DataProvider('nonSampleContent')]
    public function aborts_without_writing_anything_when_content_is_not_sample(\Closure $mutate, string $where): void
    {
        $this->fakeSampleWordPress($mutate);

        $this->assertSame(40, $this->export());
        $output = Artisan::output();
        $this->assertStringContainsString('非示例內容', $output);
        $this->assertStringContainsString($where, $output);
        $this->assertSame([], is_dir($this->out) ? $this->filesUnder('') : [], '中止時不得留下任何檔案');
    }

    #[Test]
    public function production_media_and_sanitizer_policies_stay_strict_after_export(): void
    {
        $this->fakeSampleWordPress();
        $this->assertSame(0, $this->export(), Artisan::output());

        // 正式政策沒有為了假資料放寬：相對路徑的合成媒體在正式路徑一律不合格
        $media = $this->app->make(MediaPolicy::class);
        $this->assertNull($media->imageUrl('http://evil.example/a.png'), '外部 http 圖片仍不允許');
        $this->assertNull($media->imageUrl('/mock-media/'.MockMedia::COVER), '相對路徑圖片仍不允許');
        $this->assertSame('https://cdn.example.org/ok.png', $media->imageUrl('https://cdn.example.org/ok.png'));
        $this->assertNull(MediaPolicy::audioSource('/mock-media/'.MockMedia::AUDIO), '相對路徑音訊仍不允許');
        $this->assertNull(MediaPolicy::audioSource('https://evil.example/ep.mp3'));
        $this->assertNull(MediaPolicy::youtubeId('not-valid/id!'));
        $clean = $this->app->make(Sanitizer::class)->sanitize('<p>x</p><img src="/mock-media/'.MockMedia::INLINE.'" alt="合成">')->html();
        $this->assertStringNotContainsString('<img', $clean, 'Sanitizer 仍拒絕相對路徑圖片');
        $this->assertStringContainsString('rich-media-removed', $clean);

        // 正式 API 仍輸出原始的 CMS 網址（轉換只發生在匯出工具裡）
        $this->get('/api/v1/articles/sample-article')->assertOk()
            ->assertJsonPath('data.cover.url', 'http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/a.png')
            ->assertDontSee(self::SENTINEL);
    }

    #[Test]
    public function refuses_missing_out_option_and_non_empty_output_without_touching_files(): void
    {
        $this->fakeSampleWordPress();
        $this->assertSame(10, Artisan::call('content:export-api-mocks'));
        $this->assertStringContainsString('--out', Artisan::output());

        mkdir($this->out.'/mocks', 0775, true);
        file_put_contents($this->out.'/mocks/keep.txt', 'keep');
        $this->assertSame(10, $this->export());
        $this->assertStringContainsString('已有內容', Artisan::output());
        $this->assertSame(['mocks/keep.txt'], $this->filesUnder(''), '既有檔案不動、也不新增任何檔案');
        $this->assertSame('keep', file_get_contents($this->out.'/mocks/keep.txt'));
    }

    #[Test]
    public function refuses_to_run_outside_development_and_testing(): void
    {
        $this->fakeSampleWordPress();
        $this->app['env'] = 'production';
        $this->assertSame(40, $this->export());
        $this->assertStringContainsString('APP_ENV=production', Artisan::output());
        $this->assertFalse(is_dir($this->out));
    }

    private function export(): int
    {
        return Artisan::call('content:export-api-mocks', ['--out' => $this->out]);
    }

    /**
     * 假 WordPress：全是示例內容（文章兩篇、影片、Podcast、兩個頁面），選單另含一個不存在的頁面與外部連結。
     *
     * @param  (\Closure(array<string, mixed>): array<string, mixed>)|null  $mutate
     */
    #[Test]
    public function cms_links_in_content_are_rewritten_and_any_leftover_cms_url_aborts(): void
    {
        $this->fakeSampleWordPress(function (array $items): array {
            $items['post']['body_html'] = '<p><a href="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/full.png">看大圖</a></p>'
                .'<blockquote cite="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/source.pdf"><p>引文</p></blockquote>'
                .'<p><a href="https://example.com/">外部連結</a></p>';

            return $items;
        });
        $payload = (array) $this->get('/api/v1/articles/sample-article')->assertOk()->json();
        $this->assertStringContainsString('wp-content/uploads/2026/11/full.png', (string) $payload['data']['content_html'], '前提：真實 API 的正文含 CMS 連結');

        $transformer = $this->app->make(MockTransformer::class);
        $out = $transformer->transform('detail', $payload, '/api/v1/articles/sample-article');
        $html = (string) $out['data']['content_html'];
        $this->assertStringNotContainsString('cms.mysite.localhost', $html);
        $this->assertStringNotContainsString('/wp-content/uploads/', $html);
        $this->assertStringContainsString('href="'.MockMedia::url(MockMedia::INLINE).'"', $html);
        $this->assertStringContainsString('cite="'.MockMedia::url(MockMedia::INLINE).'"', $html);
        $this->assertStringContainsString('href="https://example.com/"', $html, '外部示例連結保留');

        // 轉換層沒處理到的欄位殘留 CMS 網址：全文檢查必須中止
        $payload['data']['excerpt'] = '摘要提到 http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/x.png';
        $this->expectException(\App\Domain\Content\Mock\MockExportException::class);
        $this->expectExceptionMessage('殘留 CMS 網址');
        $transformer->transform('detail', $payload, '/api/v1/articles/sample-article');
    }

    private function fakeSampleWordPress(?\Closure $mutate = null): void
    {
        $data = [
            'site' => ['name' => 'mysite', 'tagline' => '影音創作紀錄', 'author_bio' => '示例作者簡介', 'social_links' => [['label' => '示例', 'url' => 'https://example.com/']], 'logo' => ['url' => 'http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/logo.png', 'alt' => '示例標誌', 'width' => 512, 'height' => 512], 'is_sample' => true],
            'post' => self::wpItem(['body_html' => '<p>第一段正文。</p><figure><img src="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/inline.png" alt="正文圖片" width="800" height="400"><figcaption>圖說</figcaption></figure><p><a href="https://example.com/">外部連結</a></p>'], true),
            'post2' => self::wpItem(['id' => 102, 'slug' => 'second-article', 'title' => '第二篇示例', 'cover' => null, 'tags' => []], true),
            'video' => self::wpItem(['id' => 202, 'type' => 'video', 'slug' => 'my-video', 'title' => '示例影片', 'categories' => [['id' => 4, 'slug' => 'media', 'name' => '影音']]], true),
            'podcast' => self::wpItem(['id' => 303, 'type' => 'podcast', 'slug' => 'ep-1', 'title' => '示例第一集'], true),
            'about' => self::wpItem(['id' => 404, 'type' => 'page', 'slug' => 'about', 'title' => '關於（示例）', 'categories' => [], 'tags' => []], true),
            'privacy' => self::wpItem(['id' => 405, 'type' => 'page', 'slug' => 'privacy', 'title' => '隱私說明（示例）', 'categories' => [], 'tags' => []], true),
        ];
        $data['search'] = [$data['post'], $data['video']];
        if ($mutate !== null) {
            $data = $mutate($data);
        }

        $byEndpoint = ['posts' => [$data['post'], $data['post2']], 'videos' => [$data['video']], 'podcasts' => [$data['podcast']], 'pages' => [$data['about'], $data['privacy']]];
        $routes = [
            '/site' => ['data' => $data['site'], 'meta' => self::meta()],
            '/menus/primary' => ['data' => ['location' => 'primary', 'items' => [['label' => '文章', 'url' => '/articles', 'external' => false], ['label' => '關於', 'url' => '/pages/about', 'external' => false]]], 'meta' => self::meta()],
            '/menus/footer' => ['data' => ['location' => 'footer', 'items' => [['label' => '隱私說明', 'url' => '/pages/privacy', 'external' => false], ['label' => '不存在的頁面', 'url' => '/pages/missing', 'external' => false], ['label' => '外部', 'url' => 'https://example.com/', 'external' => true]]], 'meta' => self::meta()],
            '/taxonomies' => ['data' => ['categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞', 'count' => 2], ['id' => 4, 'slug' => 'media', 'name' => '影音', 'count' => 1]], 'tags' => [['id' => 7, 'slug' => 'demo', 'name' => '示例', 'count' => 3]]], 'meta' => self::meta()],
            '/search' => fn (HttpRequest $r) => self::listEnvelope(($r->data()['q'] ?? '') === '示例' ? $data['search'] : [], 1, (int) ($r->data()['per_page'] ?? 12)),
            '/content/*/*/visibility' => function (HttpRequest $r) use ($byEndpoint) {
                foreach ($byEndpoint as $items) {
                    foreach ($items as $item) {
                        if (str_contains($r->url(), '/content/'.$item['type'].'/'.$item['id'].'/')) {
                            return self::visibilityEnvelope($item['type'], $item['id'], true, $item['content_version']);
                        }
                    }
                }

                return Http::response('', 404);
            },
        ];
        foreach ($byEndpoint as $endpoint => $items) {
            $routes['/'.$endpoint] = function (HttpRequest $r) use ($items) {
                $slug = $r->data()['slug'] ?? null;
                if ($slug === null) {
                    return self::listEnvelope($items, 1, (int) ($r->data()['per_page'] ?? 12));
                }
                foreach ($items as $item) {
                    if ($item['slug'] === $slug) {
                        return self::listEnvelope([$item], 1, 1);
                    }
                }

                return Http::response(['code' => 'site_content_not_found', 'message' => '找不到內容', 'data' => ['status' => 404]], 404);
            };
        }
        $this->fakeWordPress($routes);
    }

    /**
     * 逐欄檢查一份假資料：is_sample 一律 true；圖片、og_image、audio_url、content_html 的圖片只指向實際存在的合成檔；
     * excerpt 與 content_html 以哨兵開頭。
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $mediaFiles
     */
    private function assertMockNode(array $node, string $file, array $mediaFiles, string $at = '$'): void
    {
        $exists = fn (?string $url): bool => $url !== null && str_starts_with($url, '/mock-media/') && in_array('public'.$url, $mediaFiles, true);
        if (array_key_exists('is_sample', $node)) {
            $this->assertTrue($node['is_sample'], "{$file} {$at} 必須是示例內容");
        }
        $keys = array_keys($node);
        sort($keys);
        if ($keys === ['alt', 'height', 'url', 'width']) {
            $this->assertTrue($exists($node['url']), "{$file} {$at}.url 必須指向存在的合成檔：{$node['url']}");
        }
        foreach (['og_image', 'audio_url'] as $field) {
            if (array_key_exists($field, $node) && $node[$field] !== null) {
                $this->assertTrue($exists($node[$field]), "{$file} {$at}.{$field} 必須指向存在的合成檔");
            }
        }
        if (array_key_exists('excerpt', $node)) {
            $this->assertStringStartsWith(self::SENTINEL, $node['excerpt'], "{$file} {$at}.excerpt");
        }
        if (array_key_exists('content_html', $node)) {
            $this->assertStringStartsWith('<p>'.self::SENTINEL.'</p>', $node['content_html'], "{$file} {$at}.content_html");
            preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/', $node['content_html'], $m);
            foreach ($m[1] as $src) {
                $this->assertTrue($exists($src), "{$file} {$at}.content_html 的圖片 {$src} 必須指向存在的合成檔");
            }
            $this->assertSame(substr_count($node['content_html'], '<img'), count($m[1]));
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->assertMockNode($value, $file, $mediaFiles, $at.(is_int($key) ? "[{$key}]" : ".{$key}"));
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertEdgeCases(array $manifest): void
    {
        $byScenario = [];
        foreach ($manifest['files'] as $entry) {
            if ($entry['kind'] === 'edge') {
                $this->assertNull($entry['requested_at']);
                $byScenario[$entry['scenario']] = ['entry' => $entry, 'json' => $this->readJson($entry['file'])];
            }
        }

        $long = $byScenario['long-title']['json']['data'];
        $this->assertGreaterThanOrEqual(200, mb_strlen($long['title']));
        $this->assertMatchesRegularExpression('/^[\p{Han}\p{P}A-Za-z0-9 ]+$/u', $long['title'], '超長標題是中文');
        $this->assertNotNull($long['cover']);
        $this->assertStringContainsString('src="/mock-media/'.MockMedia::INLINE.'"', $long['content_html']);

        preg_match('#href="(https://example\.com/msmock/[^"]+)"#', $byScenario['long-url']['json']['data']['content_html'], $m);
        $this->assertGreaterThanOrEqual(500, strlen($m[1] ?? ''), '超長網址');

        $this->assertNull($byScenario['no-cover']['json']['data']['cover']);
        $this->assertNull($byScenario['no-cover']['json']['seo']['og_image']);

        $empty = $byScenario['empty-list'];
        $this->assertSame('/api/v1/articles', $empty['entry']['operation_path']);
        $this->assertSame([], $empty['json']['data']);
        $this->assertSame(0, $empty['json']['meta']['total']);
        $this->assertStringStartsWith(self::SENTINEL, $empty['json']['seo']['description'], '零篇列表也帶哨兵（seo.description）');

        $video = $byScenario['video-unavailable']['json']['data']['video'];
        $this->assertTrue($video['video_unavailable']);
        $this->assertNull($video['youtube_id']);
        $this->assertNull($video['embed_url']);

        $podcast = $byScenario['audio-unavailable']['json']['data'];
        $this->assertTrue($podcast['podcast']['audio_unavailable']);
        $this->assertNull($podcast['podcast']['audio_url']);
        $this->assertNotSame('', $podcast['excerpt'], '音訊不可播時簡介照常提供');

        $chinese = $byScenario['chinese-slug'];
        $slug = $chinese['json']['data']['slug'];
        $this->assertMatchesRegularExpression('/\p{Han}/u', $slug);
        $this->assertSame('/articles/'.rawurlencode($slug), $chinese['json']['data']['path']);
        $this->assertSame('/api/v1/articles/'.rawurlencode($slug), $chinese['entry']['api_path']);
        $this->assertStringContainsString('%E', $chinese['entry']['api_path']);

        $this->assertSame(200, mb_strlen($byScenario['slug-200']['json']['data']['slug']));
    }

    /** 合成媒體：PNG 結構與 CRC 正確、可解壓；WAV 標頭正確且不超過 1 秒。 */
    private function assertSyntheticMedia(): void
    {
        foreach ([MockMedia::COVER, MockMedia::LOGO, MockMedia::INLINE] as $name) {
            $png = (string) file_get_contents($this->out.'/public/mock-media/'.$name);
            $info = getimagesizefromstring($png);
            $this->assertIsArray($info, "{$name} 是合法圖檔");
            $this->assertSame(IMAGETYPE_PNG, $info[2]);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
            $offset = 8;
            $idat = '';
            $types = [];
            while ($offset < strlen($png)) {
                $length = unpack('N', substr($png, $offset, 4))[1];
                $type = substr($png, $offset + 4, 4);
                $chunk = substr($png, $offset + 8, $length);
                $this->assertSame(unpack('N', substr($png, $offset + 8 + $length, 4))[1], crc32($type.$chunk), "{$name} {$type} CRC");
                $types[] = $type;
                if ($type === 'IDAT') {
                    $idat .= $chunk;
                }
                $offset += 12 + $length;
            }
            $this->assertSame(['IHDR', 'IDAT', 'IEND'], $types);
            $this->assertSame($info[1] * (1 + 3 * $info[0]), strlen((string) gzuncompress($idat)), "{$name} 像素資料長度");
        }

        $wav = (string) file_get_contents($this->out.'/public/mock-media/'.MockMedia::AUDIO);
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVE', substr($wav, 8, 4));
        $this->assertSame(strlen($wav) - 8, unpack('V', substr($wav, 4, 4))[1]);
        $fmt = unpack('Vsize/vformat/vchannels/Vrate/VbyteRate/vblockAlign/vbits', substr($wav, 16, 20));
        $this->assertSame(1, $fmt['format'], 'PCM');
        $this->assertSame($fmt['rate'] * $fmt['blockAlign'], $fmt['byteRate']);
        $this->assertSame('data', substr($wav, 36, 4));
        $dataSize = unpack('V', substr($wav, 40, 4))[1];
        $this->assertSame(strlen($wav) - 44, $dataSize);
        $seconds = $dataSize / $fmt['byteRate'];
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(1.0, $seconds, '合成音訊不超過 1 秒');
    }

    /** @return array<string, mixed> */
    private function readJson(string $relative): array
    {
        $decoded = json_decode((string) file_get_contents($this->out.'/'.$relative), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> 相對於輸出根目錄的檔案路徑 */
    private function filesUnder(string $relative): array
    {
        $base = rtrim($this->out.'/'.$relative, '/');
        if (! is_dir($base)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $files[] = substr($file->getPathname(), strlen($this->out) + 1);
        }
        sort($files);

        return $files;
    }

    private function contract(): ValidatorInterface
    {
        return self::$contract ??= ValidatorBuilder::fromJsonFile(base_path('resources/contracts/public-api-v1.openapi.json'))->getValidator();
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(base_path('resources/contracts/public-api-v1.openapi.json')), true);
    }
}
