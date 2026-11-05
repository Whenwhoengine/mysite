<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API-05／ADR-010：scripts/ci/check-blade-raw.sh 阻擋 rich-content 以外的 `{!! !!}`。
 * 需要能讀到儲存庫的 scripts/（tools 容器須掛載；找不到時失敗而不是略過）。
 */
final class BladeRawCheckTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/blade-raw-'.bin2hex(random_bytes(4)));
        mkdir($this->tmp.'/components', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/{,*/}*.blade.php', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tmp.'/components');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function script(): string
    {
        foreach ([getenv('MYSITE_REPO_ROOT') ?: null, dirname(base_path()), '/opt/mysite'] as $root) {
            if ($root !== null && is_file($root.'/scripts/ci/check-blade-raw.sh')) {
                return $root.'/scripts/ci/check-blade-raw.sh';
            }
        }
        $this->fail('找不到 scripts/ci/check-blade-raw.sh：請在執行測試的容器掛載儲存庫的 scripts/（或設定 MYSITE_REPO_ROOT）。');
    }

    #[Test]
    #[Group('API-05')]
    public function the_real_views_pass(): void
    {
        $result = Process::run(['bash', $this->script(), '--views-dir='.resource_path('views')]);

        $this->assertSame(0, $result->exitCode(), $result->output().$result->errorOutput());
        $this->assertStringContainsString('PASS', $result->output());
    }

    #[Test]
    #[Group('API-05')]
    public function raw_output_outside_rich_content_is_blocked(): void
    {
        file_put_contents($this->tmp.'/components/rich-content.blade.php', '<div>{!! $content->html() !!}</div>');
        file_put_contents($this->tmp.'/page.blade.php', "<p>{{ \$ok }}</p>\n<p>{!! \$title !!}</p>\n");
        file_put_contents($this->tmp.'/echo.blade.php', "@php echo \$x; @endphp\n");

        $result = Process::run(['bash', $this->script(), '--views-dir='.$this->tmp]);

        $this->assertSame(30, $result->exitCode());
        $this->assertStringContainsString('page.blade.php:2', $result->output());
        $this->assertStringContainsString('echo.blade.php:1', $result->output());
    }

    #[Test]
    #[Group('API-05')]
    public function rich_content_may_only_output_safe_content(): void
    {
        file_put_contents($this->tmp.'/components/rich-content.blade.php', '<div>{!! $content->html() !!}{!! $other !!}</div>');

        $this->assertSame(30, Process::run(['bash', $this->script(), '--views-dir='.$this->tmp])->exitCode());

        file_put_contents($this->tmp.'/components/rich-content.blade.php', '<div>{!! $content->html() !!}</div>');
        $this->assertSame(0, Process::run(['bash', $this->script(), '--views-dir='.$this->tmp])->exitCode());
    }

    #[Test]
    #[Group('API-05')]
    public function help_and_usage_errors(): void
    {
        $help = Process::run(['bash', $this->script(), '--help']);
        $this->assertSame(0, $help->exitCode());
        $this->assertStringContainsString('用法', $help->output());

        $this->assertSame(10, Process::run(['bash', $this->script(), '--bogus'])->exitCode());
        $this->assertSame(10, Process::run(['bash', $this->script(), '--views-dir=/nonexistent'])->exitCode());
    }
}
