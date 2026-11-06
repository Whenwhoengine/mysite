<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Mock\ApiMockExporter;
use App\Domain\Content\Mock\MockExportException;
use App\Domain\Content\Mock\MockTransformer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * 匯出前端假資料（MySite React 前端專案 v1 §7 F2 第 5 步、定案執行書 §5 M-19）。
 * 說明文件：docs/MySiteReact施工紀錄/假資料匯出.md。
 */
final class ExportApiMocks extends Command
{
    protected $signature = 'content:export-api-mocks
        {--out= : 輸出目錄（必填；相對路徑以 Laravel 專案根 app/ 為準；其下的 mocks/ 與 public/mock-media/ 必須不存在或為空）}';

    protected $description = '以公開 API 的示例內容匯出前端假資料（只收 is_sample、媒體改寫為 /mock-media/、植入哨兵字串、契約再驗證）。'
        .'結束碼：0 成功；10 參數錯或輸出位置已有內容；20 缺開發相依套件；30 API 或契約失敗；40 遇到非示例內容或不允許的環境';

    public function handle(ApiMockExporter $exporter): int
    {
        $out = $this->option('out');
        try {
            $result = $exporter->export(is_string($out) ? $out : '');
        } catch (MockExportException $e) {
            $this->error($e->getMessage());

            return $e->exitCode;
        }

        $this->info("已匯出假資料到 {$result['out']}");
        $this->line("  API 回應：{$result['exported']} 份；合成邊界案例：{$result['edge']} 份；合成媒體：{$result['media']} 個；另有 mocks/manifest.json");
        $this->line("  有結果的搜尋查詢詞：{$result['search_query']}");
        $this->line('  哨兵字串：'.MockTransformer::SENTINEL);
        foreach ($result['skipped'] as $skip) {
            $this->warn("  略過 {$skip['api_path']}：{$skip['reason']}");
        }

        return SymfonyCommand::SUCCESS;
    }
}
