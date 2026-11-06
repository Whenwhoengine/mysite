<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Api\Concerns\AssertsPublicApiRules;

/**
 * 契約樣本集（定案執行書 M-18②、F1 驗收）：valid 全部通過契約驗證、invalid 全部失敗。
 * 前端 F3 的 zod 驗證用同一批樣本，兩邊對「什麼算合法回應」不會各自漂移。不碰資料庫。
 */
final class PublicApiSamplesTest extends TestCase
{
    use AssertsPublicApiRules;

    private const DIR = __DIR__.'/../../../resources/contracts';

    private static ?ValidatorInterface $contract = null;

    private static function contract(): ValidatorInterface
    {
        return self::$contract ??= ValidatorBuilder::fromJsonFile(self::DIR.'/public-api-v1.openapi.json')->getValidator();
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return (array) json_decode((string) file_get_contents(self::DIR.'/public-api-v1/samples/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string, string, int}> */
    private static function entries(string $group): array
    {
        $out = [];
        foreach (self::manifest()[$group] as $e) {
            $out[$e['file']] = [$e['file'], $e['operation'], (int) $e['status']];
        }

        return $out;
    }

    /** @return array<string, array{string, string, int}> */
    public static function validSamples(): array
    {
        return self::entries('valid');
    }

    /** @return array<string, array{string, string, int}> */
    public static function invalidSamples(): array
    {
        return self::entries('invalid');
    }

    private static function response(string $file, int $status): Response
    {
        $body = (string) file_get_contents(self::DIR.'/public-api-v1/samples/'.$file);

        return new Response($body, $status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    #[Test]
    #[Group('PAPI-01')]
    #[DataProvider('validSamples')]
    public function valid_sample_passes_the_contract(string $file, string $operation, int $status): void
    {
        $this->assertTrue(self::contract()->validate(self::response($file, $status), $operation, 'get'), $file);
        if ($status === 200) {
            $this->assertPublicApiRules((array) json_decode((string) file_get_contents(self::DIR.'/public-api-v1/samples/'.$file), true), $operation, $file);
        }
    }

    #[Test]
    #[Group('PAPI-01')]
    #[DataProvider('invalidSamples')]
    public function invalid_sample_is_rejected_by_the_contract(string $file, string $operation, int $status): void
    {
        $this->expectException(ValidationException::class);
        self::contract()->validate(self::response($file, $status), $operation, 'get');
    }

    #[Test]
    public function sample_set_is_complete_and_carries_the_sentinel(): void
    {
        $m = self::manifest();
        $this->assertSame('MSMOCK-7F3A9C1E', $m['sentinel']);
        $ops = array_unique(array_column($m['valid'], 'operation'));
        $contract = (array) json_decode((string) file_get_contents(self::DIR.'/public-api-v1.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_keys($contract['paths']) as $path) {
            $this->assertContains($path, $ops, "契約端點 {$path} 至少要有一個有效樣本");
        }
        foreach ([404, 422, 503] as $status) {
            $this->assertContains($status, array_column($m['valid'], 'status'), "要有 {$status} 樣本");
        }
        foreach ($m['valid'] as $e) {
            $body = (string) file_get_contents(self::DIR.'/public-api-v1/samples/'.$e['file']);
            $noContent = [
                'valid/site.fallback.json', 'valid/home.empty.json', 'valid/search.initial.json', 'valid/search.zero.json',
                'valid/articles.index.empty.json', 'valid/videos.index.empty.json', 'valid/podcasts.index.empty.json',
                'valid/categories.show.listing.empty.json', 'valid/tags.show.overview.empty.json',
            ];
            if ($e['status'] === 200 && ! in_array($e['file'], $noContent, true)) {
                $this->assertStringContainsString('MSMOCK-7F3A9C1E', $body, "{$e['file']} 要帶假資料哨兵字串");
            }
            $this->assertDoesNotMatchRegularExpression('#https?://(?!(example\.com|www\.youtube\.com|www\.youtube-nocookie\.com)(/|"|$))#', $body, "{$e['file']} 只能用示例網域或 YouTube");
        }
    }
}
