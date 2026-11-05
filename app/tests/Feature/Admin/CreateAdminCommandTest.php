<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Feature\Commerce\CommerceTestCase;

/** mysite:admin:create：密碼只從檔案讀、已存在 no-op、無固定示範帳密（M7、COM-10）。 */
final class CreateAdminCommandTest extends CommerceTestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'pw');
        chmod($this->file, 0600);
        file_put_contents($this->file, "a-strong-password-123\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    #[Test]
    #[Group('COM-10')]
    public function creates_an_admin_from_a_password_file(): void
    {
        $this->artisan('mysite:admin:create', ['--email' => 'Owner@Example.com', '--name' => '站長', '--role' => 'admin', '--password-file' => $this->file])
            ->expectsOutputToContain('已建立')
            ->assertSuccessful();
        $user = User::query()->sole();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('a-strong-password-123', $user->password));
        $this->assertNull($user->disabled_at);
        $audit = AuditLog::query()->where('action', 'admin.user_created')->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertStringNotContainsString('a-strong-password-123', json_encode($audit->toArray()));
    }

    #[Test]
    #[Group('COM-10')]
    public function existing_account_is_a_noop_and_password_is_not_reset(): void
    {
        $existing = User::factory()->viewer()->create(['email' => 'owner@example.com', 'password' => Hash::make('original-password-1')]);
        file_put_contents($this->file, "completely-different-pw\n");
        $this->artisan('mysite:admin:create', ['--email' => 'owner@example.com', '--name' => 'x', '--role' => 'admin', '--password-file' => $this->file])
            ->expectsOutputToContain('no-op')
            ->assertSuccessful();
        $existing->refresh();
        $this->assertTrue(Hash::check('original-password-1', $existing->password));
        $this->assertSame(UserRole::Viewer, $existing->role);
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    #[Group('COM-10')]
    public function rejects_invalid_arguments_short_passwords_and_missing_file(): void
    {
        $this->artisan('mysite:admin:create', ['--email' => 'bad', '--name' => 'x', '--password-file' => $this->file])->assertFailed();
        $this->artisan('mysite:admin:create', ['--email' => 'a@example.com', '--name' => 'x', '--role' => 'root', '--password-file' => $this->file])->assertFailed();
        $this->artisan('mysite:admin:create', ['--email' => 'a@example.com', '--name' => 'x', '--password-file' => '/nonexistent/pw'])->assertFailed();
        file_put_contents($this->file, "short\n");
        $this->artisan('mysite:admin:create', ['--email' => 'a@example.com', '--name' => 'x', '--password-file' => $this->file])->assertFailed();
        file_put_contents($this->file, str_repeat('x', 73));
        $this->artisan('mysite:admin:create', ['--email' => 'a@example.com', '--name' => 'x', '--password-file' => $this->file])->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    #[Group('COM-10')]
    public function password_cannot_be_given_on_the_command_line(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->artisan('mysite:admin:create', ['--email' => 'a@example.com', '--name' => 'x', '--password' => 'plain-text-password']);
    }

    #[Test]
    #[Group('COM-10')]
    public function viewer_role_is_supported_and_warns_on_world_readable_file(): void
    {
        chmod($this->file, 0644);
        $this->artisan('mysite:admin:create', ['--email' => 'v@example.com', '--name' => '唯讀', '--role' => 'viewer', '--password-file' => $this->file])
            ->expectsOutputToContain('chmod 600')
            ->assertSuccessful();
        $this->assertSame(UserRole::Viewer, User::query()->sole()->role);
    }
}
