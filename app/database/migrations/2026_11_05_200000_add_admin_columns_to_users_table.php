<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 管理後臺帳號欄位：角色（admin／viewer；null＝無後臺權限）、停用時間、Fortify 2FA 欄位。
 * 公開註冊關閉；帳號只由 `php artisan mysite:admin:create` 建立。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->nullable()->after('password')->index();
            $table->timestamp('disabled_at')->nullable()->after('role');
            $table->text('two_factor_secret')->nullable()->after('disabled_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'disabled_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
