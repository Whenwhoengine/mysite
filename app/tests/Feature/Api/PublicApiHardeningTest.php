<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 公開 API 的入口守門（第一輪查錯補強）：
 * 「/index.php/api/…」繞道、非 GET 方法與 OPTIONS、跨來源讀取、錯誤回應的標頭、Host 標頭、除錯模式不洩漏。
 */
final class PublicApiHardeningTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        Sleep::fake();
        $this->fakeWordPress([
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => ['data' => ['name' => 'mysite', 'tagline' => '', 'author_bio' => '', 'social_links' => [], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()],
            '/posts' => self::listEnvelope([self::wpItem()]),
        ]);
    }

    private function assertGuardedHeaders(\Illuminate\Testing\TestResponse $response, string $label): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$label}：no-store");
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), "{$label}：X-Robots-Tag");
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'), "{$label}：不得有 Access-Control-Allow-Origin");
        $this->assertSame([], $response->baseResponse->headers->getCookies(), "{$label}：不得有 Set-Cookie");
    }

    #[Test]
    #[Group('PAPI-08')]
    public function index_php_prefix_cannot_reach_the_api(): void
    {
        // 前端控制器後面接路徑時，Laravel 會把 /index.php 當成 base URL、把後面當成路由路徑
        $server = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => public_path('index.php'), 'PHP_SELF' => '/index.php/api/v1/site'];
        $response = $this->call('GET', '/index.php/api/v1/site', [], [], [], $server);
        $response->assertNotFound()->assertJsonPath('error', 'CONTENT_NOT_FOUND')->assertDontSee('mysite","tagline', false);
        $this->assertGuardedHeaders($response, '/index.php/api/v1/site');
        $this->assertSame([], $this->wpCallsMatching('/site*'), '繞道請求不得送往上游');
    }

    #[Test]
    #[Group('PAPI-08')]
    public function front_controller_page_links_do_not_carry_the_index_php_prefix(): void
    {
        // 直接開 /index.php 仍是首頁（FE-20：/index.php 照舊交給 Laravel），但頁面連結不得帶 /index.php/（那些路徑在 gateway 一律 404）
        $this->fakeWordPress([
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
            '/posts' => self::listEnvelope([self::wpItem()]),
            '/videos' => self::listEnvelope([]),
            '/podcasts' => self::listEnvelope([]),
        ]);
        $server = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => public_path('index.php'), 'PHP_SELF' => '/index.php'];
        $page = $this->call('GET', '/index.php', [], [], [], $server)->assertOk();
        $this->assertStringNotContainsString('/index.php/', (string) $page->getContent());
        $this->assertStringContainsString('/feed.xml"', (string) $page->getContent());
        // 之後的一般請求不受影響（測試工具會以上一個請求的根目錄組網址，所以這裡用完整網址；正式環境每個請求各自獨立）
        $this->assertStringNotContainsString('/index.php', (string) $this->get(config('app.url').'/articles')->assertOk()->getContent());
    }

    #[Test]
    #[Group('PAPI-02')]
    public function only_get_and_head_are_allowed_and_cors_is_not_offered(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->call($method, '/api/v1/site', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
            $response->assertStatus(405)->assertJsonPath('error', 'HTTP_405')->assertHeader('Allow', 'GET, HEAD');
            $this->assertGuardedHeaders($response, $method);
        }
        $get = $this->withHeaders(['Origin' => 'https://evil.example'])->get('/api/v1/site')->assertOk();
        $this->assertGuardedHeaders($get, 'GET 帶 Origin');
        $head = $this->call('HEAD', '/api/v1/site');
        $head->assertOk();
        $this->assertGuardedHeaders($head, 'HEAD');
    }

    #[Test]
    #[Group('PAPI-02')]
    public function unknown_routes_and_errors_carry_the_same_headers(): void
    {
        $unknown = $this->get('/api/v1/unknown')->assertNotFound()->assertJsonPath('error', 'CONTENT_NOT_FOUND');
        $this->assertGuardedHeaders($unknown, '未知路徑');
        $badPage = $this->get('/api/v1/articles?page=0')->assertNotFound();
        $this->assertGuardedHeaders($badPage, 'page=0');
        $notApi = $this->get('/api')->assertNotFound();
        $this->assertFalse($notApi->headers->has('Access-Control-Allow-Origin'));
    }

    #[Test]
    #[Group('PAPI-08')]
    public function host_header_does_not_change_or_leak_into_the_response(): void
    {
        // v1 §5.5：前端以專用別名連線時，Laravel 不需設定可接受的 Host；canonical 由 APP_URL 組成，不讀 Host
        foreach (['public-api', 'app-web:8080', 'evil.example'] as $host) {
            $response = $this->withHeaders(['Host' => $host])->get('/api/v1/articles')->assertOk();
            $response->assertJsonPath('seo.canonical_path', '/articles');
            $this->assertStringNotContainsString($host, (string) $response->getContent(), "Host {$host} 不得出現在回應");
        }
    }

    #[Test]
    #[Group('PAPI-01')]
    public function maintenance_mode_returns_a_json_503_matching_the_contract(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60]);
        try {
            $response = $this->get('/api/v1/site')->assertStatus(503)->assertJsonPath('error', 'HTTP_503');
            $this->assertGuardedHeaders($response, '維護模式');
            $validator = \Osteel\OpenApi\Testing\ValidatorBuilder::fromJsonFile(base_path('resources/contracts/public-api-v1.openapi.json'))->getValidator();
            $this->assertTrue($validator->validate($response->baseResponse, '/api/v1/site', 'get'));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    #[Test]
    #[Group('PAPI-05')]
    public function debug_mode_still_returns_the_fixed_json_error(): void
    {
        config(['app.debug' => true]);
        // 只在本測試掛一條會丟出未預期例外的 API 路由（模擬程式錯誤）
        Route::get('/api/v1/__hardening-throw', fn () => throw new \RuntimeException('secret detail /var/www/app/app/Foo.php'));

        $response = $this->get('/api/v1/__hardening-throw')->assertStatus(500)->assertJsonPath('error', 'HTTP_500');
        $this->assertSame(['error', 'message', 'request_id'], array_keys((array) $response->json()));
        $this->assertStringNotContainsString('secret detail', (string) $response->getContent());
        $this->assertStringNotContainsString('/var/www', (string) $response->getContent());
        $this->assertGuardedHeaders($response, '除錯模式 500');
        Http::assertNothingSent();
    }
}
