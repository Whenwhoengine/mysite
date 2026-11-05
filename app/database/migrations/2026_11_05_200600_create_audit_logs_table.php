<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 去秘密化稽核紀錄。metadata 已遮罩秘密欄位；IP 只存最小化前綴（IPv4 /24、IPv6 /48）；不存 user agent。
 * actor_user_id 不設 FK，避免使用者資料異動影響稽核保存。保存期限由 AUDIT_RETENTION_DAYS 控制。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_type', 16);
            $table->string('action', 64)->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_prefix', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
