<?php

declare(strict_types=1);

namespace Tests\Unit\Secrets;

use App\Support\Secrets\SecretFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** GOV-01／ADR-006：*_FILE 契約（缺檔、空值、權限、換行）。 */
final class SecretFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mysite-secret-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        putenv('MYSITE_TEST_SECRET_FILE');
        unset($_ENV['MYSITE_TEST_SECRET_FILE'], $_SERVER['MYSITE_TEST_SECRET_FILE']);
    }

    private function write(string $name, string $content, int $mode = 0600): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }

    #[Test]
    public function it_reads_and_strips_trailing_newline(): void
    {
        $path = $this->write('ok', "s3cret-value\n");
        $this->assertSame('s3cret-value', SecretFiles::readPath('X', $path));
    }

    #[Test]
    public function it_rejects_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        SecretFiles::readPath('X', $this->dir.'/missing');
    }

    #[Test]
    public function it_rejects_empty_or_whitespace(): void
    {
        $path = $this->write('empty', "  \n");
        $this->expectException(RuntimeException::class);
        SecretFiles::readPath('X', $path);
    }

    #[Test]
    public function it_rejects_world_readable_file(): void
    {
        $path = $this->write('wide', 'value', 0644);
        $this->expectExceptionMessage('權限過寬');
        SecretFiles::readPath('X', $path);
    }

    #[Test]
    public function it_accepts_group_readable_file(): void
    {
        $path = $this->write('group', 'value', 0640);
        $this->assertSame('value', SecretFiles::readPath('X', $path));
    }

    #[Test]
    public function it_rejects_multiline_content(): void
    {
        $path = $this->write('multi', "line1\nline2\n");
        $this->expectExceptionMessage('多行');
        SecretFiles::readPath('X', $path);
    }

    #[Test]
    public function required_secrets_fail_in_production_when_missing(): void
    {
        foreach (SecretFiles::EXPORTED as $name) {
            putenv($name.'_FILE');
            unset($_ENV[$name.'_FILE'], $_SERVER[$name.'_FILE']);
        }
        $this->expectExceptionMessage('缺少必要秘密');
        SecretFiles::assertRequiredForEnvironment('production');
    }

    #[Test]
    public function unknown_environment_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        SecretFiles::assertRequiredForEnvironment('local');
    }
}
