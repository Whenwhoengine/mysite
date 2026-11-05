<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * 建立 Laravel 後臺帳號（無公開註冊；不建立任何固定示範帳密）。
 * 密碼只從檔案讀取（不接受命令列明文，避免進入 shell history 與行程列表）；帳號已存在時不重設密碼，回報 no-op。
 */
final class CreateAdminUser extends Command
{
    protected $signature = 'mysite:admin:create
        {--email= : 登入 email}
        {--name= : 顯示名稱}
        {--role=admin : 角色 admin 或 viewer}
        {--password-file= : 存放密碼的檔案路徑（只讀第一行，去除結尾換行）}';

    protected $description = '建立 Laravel 管理後臺帳號（密碼從檔案讀取；已存在則 no-op）';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $name = trim((string) $this->option('name'));
        $role = UserRole::tryFrom((string) $this->option('role'));
        $file = (string) $this->option('password-file');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            ['email' => ['required', 'email:rfc', 'max:255'], 'name' => ['required', 'string', 'max:100']],
        );
        if ($validator->fails() || $role === null) {
            $this->error('參數不合法：需要 --email（有效 email）、--name（1–100 字）與 --role=admin|viewer。');

            return SymfonyCommand::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->info("no-op：帳號 {$email} 已存在，未變更密碼或角色。");

            return SymfonyCommand::SUCCESS;
        }

        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            $this->error('必須以 --password-file 指定可讀取的密碼檔。');

            return SymfonyCommand::INVALID;
        }
        $perms = fileperms($file);
        if ($perms !== false && ($perms & 0o004) !== 0) {
            $this->warn('提醒：密碼檔可被其他使用者讀取，建議 chmod 600，用完即刪。');
        }
        $password = rtrim((string) strtok((string) file_get_contents($file, false, null, 0, 4096), "\n"), "\r");
        $length = strlen($password);
        if (mb_strlen($password) < 12 || $length > 72) {
            $this->error('密碼長度必須至少 12 個字元、最多 72 bytes。');

            return SymfonyCommand::INVALID;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
        ])->save();

        $audit->record('admin.user_created', null, $user, null, ['role' => $role->value, 'via' => 'artisan'], 'system');
        $this->info("已建立 {$role->label()}帳號 {$email}。請登入後於「帳號安全」完成 2FA 登記。");

        return SymfonyCommand::SUCCESS;
    }
}
