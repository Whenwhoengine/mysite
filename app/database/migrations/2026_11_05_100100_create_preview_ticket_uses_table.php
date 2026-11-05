<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 預覽票券使用紀錄（API-11）：jti 為主鍵，第二次寫入即失敗 → 票券只能交換一次（並發也只成功一次）。
 * 不保存票券本身。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_ticket_uses', function (Blueprint $table): void {
            $table->char('jti', 32)->primary();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->char('snapshot_id', 32);
            $table->unsignedBigInteger('issued_by');
            $table->timestamp('expires_at');
            $table->timestamp('used_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_ticket_uses');
    }
};
