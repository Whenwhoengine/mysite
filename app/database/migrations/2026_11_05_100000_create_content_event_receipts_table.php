<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 內容事件接收紀錄（契約 §4、API-07）：event_id 唯一；保存 body 的 SHA-256 以判斷「同 ID 不同內容」。
 * 只存事件中繼資料，不存正文；保存 30 天後由排程清理。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 36)->unique();
            $table->string('key_id', 16);
            $table->char('body_sha256', 64);
            $table->string('event_type', 32);
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('content_version');
            $table->string('status', 16);
            $table->timestamp('occurred_at');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('received_count')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 191)->nullable();
            $table->timestamps();

            $table->index(['content_type', 'content_id']);
            $table->index('created_at');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_event_receipts');
    }
};
