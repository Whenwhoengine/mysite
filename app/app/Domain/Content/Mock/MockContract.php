<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 以公開 API 契約（app/resources/contracts/public-api-v1.openapi.json）驗證假資料。
 *
 * 用與 PHPUnit 相同的驗證套件（osteel/openapi-httpfoundation-testing，開發相依套件）：
 * 把 JSON 包成與 API 相同標頭的 HTTP 回應，依「operation path＋GET＋狀態碼」驗證；不合即中止。
 */
final class MockContract
{
    /** 契約正本位置（相對 Laravel 專案根）。 */
    public const FILE = 'resources/contracts/public-api-v1.openapi.json';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private readonly ValidatorInterface $validator;

    /** @var array<string, mixed> */
    private readonly array $spec;

    private readonly string $sha256;

    public function __construct(private readonly string $path)
    {
        if (! class_exists(ValidatorBuilder::class)) {
            throw new MockExportException('找不到契約驗證套件 osteel/openapi-httpfoundation-testing（開發相依套件）；請在含開發相依套件的 development／testing 環境執行。', MockExportException::EXTERNAL);
        }
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $spec = json_decode($raw, true);
        if (! is_array($spec) || ! is_array($spec['paths'] ?? null)) {
            throw new MockExportException("讀不到契約檔：{$path}", MockExportException::EXTERNAL);
        }
        $this->spec = $spec;
        $this->sha256 = hash('sha256', $raw);
        $this->validator = ValidatorBuilder::fromJsonFile($path)->getValidator();
    }

    public static function default(): self
    {
        return new self(base_path(self::FILE));
    }

    /** API 路徑（可含查詢字串）→ 契約的 operation path。 */
    public static function operationPath(string $apiPath): string
    {
        $path = (string) parse_url($apiPath, PHP_URL_PATH);
        if (preg_match('#^/api/v1/(site|home|search|articles|videos|podcasts)$#D', $path) === 1) {
            return $path;
        }
        if (preg_match('#^/api/v1/(articles|videos|podcasts|pages|categories|tags)/[^/]+$#D', $path, $m) === 1) {
            return "/api/v1/{$m[1]}/{slug}";
        }

        throw new MockExportException("無法對應契約的 API 路徑：{$apiPath}");
    }

    public function operationId(string $operationPath): string
    {
        $id = $this->spec['paths'][$operationPath]['get']['operationId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new MockExportException("契約沒有這個 operation：GET {$operationPath}");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>|string  $json  JSON 字串或已解碼的關聯陣列
     *
     * @throws MockExportException
     */
    public function validate(array|string $json, string $operationPath, int $status, string $label): void
    {
        $body = is_string($json) ? $json : json_encode($json, self::JSON_FLAGS);
        $response = new Response($body, $status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        try {
            $this->validator->validate($response, $operationPath, 'get');
        } catch (Throwable $e) {
            throw new MockExportException("{$label} 不符合契約（GET {$operationPath} {$status}）：".self::reason($e));
        }
    }

    public function version(): string
    {
        return (string) ($this->spec['info']['version'] ?? '');
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function path(): string
    {
        return $this->path;
    }

    private static function reason(Throwable $e): string
    {
        $messages = [];
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $messages[] = $cur->getMessage();
        }

        return implode('；', array_unique($messages));
    }
}
